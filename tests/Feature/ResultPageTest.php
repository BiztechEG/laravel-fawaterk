<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Results\ResultSigner;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use BiztechEG\Fawaterk\Webhooks\WebhookType;
use BiztechEG\Fawaterk\Webhooks\WebhookUrls;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

class ResultPageTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->fawaterk();
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_checkouts_send_the_signed_result_url_as_every_return_url(): void
    {
        $result = Fawaterk::checkout($this->order());

        $urls = $this->fake->created()[0]->toPayload()['redirectionUrls'];
        $expected = Fawaterk::resultUrl($this->payment($result->paymentUuid));

        $this->assertStringStartsWith('https://shop.test/fawaterk/result/'.$result->paymentUuid.'/', $urls['successUrl']);
        foreach (['failUrl', 'pendingUrl', 'backUrl'] as $key) {
            $this->assertSame($urls['successUrl'], $urls[$key]);
        }
        $this->assertSame(200, $this->visit($urls['successUrl'])->status());
        $this->assertStringStartsWith('https://shop.test/fawaterk/result/'.$result->paymentUuid.'/', (string) $expected);
    }

    public function test_a_profile_return_url_replaces_only_its_own_key(): void
    {
        Fawaterk::checkout($this->order(), new CheckoutContext('hosted', overrides: ['return_urls' => ['success' => 'https://shop.test/thanks']]));

        $urls = $this->fake->created()[0]->toPayload()['redirectionUrls'];

        $this->assertSame('https://shop.test/thanks', $urls['successUrl']);
        $this->assertStringStartsWith('https://shop.test/fawaterk/result/', $urls['failUrl']);
    }

    public function test_without_the_route_no_result_urls_are_sent(): void
    {
        $this->app['router']->setRoutes(new RouteCollection);
        $this->app['router']->fawaterkWebhooks();

        $result = Fawaterk::checkout($this->order());
        $urls = $this->fake->created()[0]->toPayload()['redirectionUrls'];

        $this->assertSame(['webhookUrl'], array_keys($urls));
        $this->assertNull(Fawaterk::resultUrl($this->payment($result->paymentUuid)));
    }

    public function test_the_page_shows_only_the_state_amount_and_a_way_to_continue(): void
    {
        $payment = $this->started();

        $response = $this->visit($this->urlFor($payment));

        $response->assertOk();
        $response->assertSee('Waiting for your payment');
        $response->assertSee('150.00 EGP');
        $response->assertSee((string) $payment->checkout_url, false);
        $response->assertSee('https://shop.test/', false);
        $response->assertDontSee((string) $payment->payable->getAttribute('number'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_query_parameters_fawaterk_adds_do_not_matter(): void
    {
        $payment = $this->started();

        $this->visit($this->urlFor($payment).'?invoice_id=12&hashKey=abc&status=paid')->assertOk()->assertSee('Waiting for your payment');
    }

    public function test_tampered_expired_or_malformed_links_are_403(): void
    {
        $payment = $this->started();
        $other = $this->started();
        [$uuid, $expires, $signature] = $this->parts($this->urlFor($payment));

        $this->visit("/fawaterk/result/{$uuid}/{$expires}/".strrev($signature))->assertStatus(403);
        $this->visit("/fawaterk/result/{$other->uuid}/{$expires}/{$signature}")->assertStatus(403);
        $this->visit('/fawaterk/result/'.$uuid.'/'.($expires + 1).'/'.$signature)->assertStatus(403);
        $this->visit("/fawaterk/result/{$uuid}/{$expires}/".strtoupper($signature))->assertStatus(403);
        $this->visit("/fawaterk/result/not-a-uuid/{$expires}/{$signature}")->assertStatus(403);
        $this->visit("/fawaterk/result/{$uuid}/0{$expires}/{$signature}")->assertStatus(403);

        Carbon::setTestNow(Carbon::createFromTimestamp($expires + 1));
        $this->visit("/fawaterk/result/{$uuid}/{$expires}/{$signature}")->assertStatus(403)->assertSee('This link is not valid');

        $this->assertSame(0, $this->fake->getTransactionCalls());
    }

    public function test_a_valid_link_for_a_missing_or_other_environment_row_is_404(): void
    {
        $payment = $this->started();
        $url = $this->urlFor($payment);

        $payment->forceFill(['environment' => 'live'])->save();
        $this->visit($url)->assertNotFound();

        $payment->delete();
        $this->visit($url)->assertNotFound();
    }

    public function test_json_on_request_with_no_customer_data(): void
    {
        $payment = $this->started();

        $response = $this->visit($this->urlFor($payment), ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertExactJson([
            'payment' => $payment->uuid,
            'state' => 'awaiting_payment',
            'paid' => false,
            'received' => false,
            'amount' => '150.00',
            'currency' => 'EGP',
            'reference' => null,
            'reference_expires_at' => null,
            'paid_at' => null,
            'checkout_url' => $payment->checkout_url,
            'back_url' => 'https://shop.test/',
        ]);

        $this->visit('/fawaterk/result/x/1/y', ['Accept' => 'application/json'])->assertStatus(403)->assertExactJson(['error' => 'invalid_link']);
    }

    public function test_a_visit_re_reads_and_applies_a_payment_the_webhook_has_not_brought_yet(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, transactionId: 777);

        $this->visit($this->urlFor($payment))->assertOk()->assertSee('Payment received');

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(777, $payment->fawaterk_transaction_id);
        Event::assertDispatched(PaymentPaid::class, 1);
    }

    public function test_re_reads_are_throttled_and_paid_rows_are_not_re_read(): void
    {
        $payment = $this->started();
        $url = $this->urlFor($payment);

        $this->visit($url);
        $this->visit($url);
        $this->assertSame(1, $this->fake->getTransactionCalls(), 'at most one re-read per 10 seconds');

        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->fake->markPaid((string) $payment->intent_key);
        $this->visit($url)->assertSee('Payment received');
        $this->assertSame(2, $this->fake->getTransactionCalls());

        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->visit($url)->assertSee('Payment received');
        $this->assertSame(2, $this->fake->getTransactionCalls(), 'paid never goes back');
    }

    public function test_an_unpaid_visit_brings_the_re_check_forward(): void
    {
        $payment = $this->started();
        $payment->forceFill(['next_check_at' => Carbon::now()->addHour()])->save();

        $this->visit($this->urlFor($payment));

        $this->assertTrue($payment->refresh()->next_check_at->lte(Carbon::now()->addMinutes(2)));
    }

    public function test_the_page_still_answers_when_fawaterk_is_down(): void
    {
        $payment = $this->started();
        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down'));

        $this->visit($this->urlFor($payment))->assertOk()->assertSee('Waiting for your payment');
    }

    public function test_a_post_back_is_answered_with_a_get_of_the_same_path(): void
    {
        $payment = $this->started();
        $url = $this->urlFor($payment);

        $response = $this->call('POST', $url.'?status=paid');

        $response->assertStatus(303);
        $this->assertSame($url, $response->headers->get('Location'), 'the same URL, built from config');
        $this->assertSame(0, $this->fake->getTransactionCalls());
        $this->call('POST', '/fawaterk/result/x/1/y')->assertStatus(403);
    }

    public function test_the_back_url_comes_from_the_callback_then_the_profile_then_config(): void
    {
        config()->set('fawaterk.profiles.hosted.return_urls', ['back' => 'https://shop.test/orders']);
        config()->set('fawaterk.results.back_url', 'https://shop.test/account');
        $payment = $this->started();
        $url = $this->urlFor($payment);

        Fawaterk::resultBackUrlUsing(fn (FawaterkPayment $row) => '/orders/'.$row->payable_id);
        $this->assertSame('/orders/'.$payment->payable_id, $this->jsonFor($url)['back_url']);

        Fawaterk::resultBackUrlUsing(fn () => null);
        $this->assertSame('https://shop.test/orders', $this->jsonFor($url)['back_url']);

        config()->set('fawaterk.profiles.hosted.return_urls', []);
        $this->assertSame('https://shop.test/account', $this->jsonFor($url)['back_url']);
    }

    public function test_unsafe_back_and_redirect_urls_are_ignored(): void
    {
        $payment = $this->started();
        $url = $this->urlFor($payment);

        foreach (['javascript:alert(1)', '//evil.test/x', 'http://shop.test/x', '/\\evil.test', ' /x', 'https://user@evil.test/'] as $bad) {
            Fawaterk::resultBackUrlUsing(fn () => $bad);
            Fawaterk::resultRedirectUsing(fn () => $bad);

            $this->assertSame('https://shop.test/', $this->jsonFor($url)['back_url'], $bad);
            $this->visit($url)->assertOk();
        }

        Fawaterk::resultBackUrlUsing(fn () => throw new \RuntimeException('app bug'));
        Fawaterk::resultRedirectUsing(fn () => throw new \RuntimeException('app bug'));
        $this->visit($url)->assertOk();
    }

    public function test_the_app_can_send_the_payer_to_its_own_page_after_the_re_read(): void
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);
        $seen = null;
        Fawaterk::resultRedirectUsing(function (FawaterkPayment $row) use (&$seen) {
            $seen = $row->status;

            return 'https://shop.test/orders/'.$row->payable_id;
        });

        $response = $this->visit($this->urlFor($payment));

        $response->assertStatus(303);
        $this->assertSame('https://shop.test/orders/'.$payment->payable_id, $response->headers->get('Location'));
        $this->assertSame(PaymentStatus::Paid, $seen, 'the callback sees the re-read row');
        $this->assertSame('paid', $this->jsonFor($this->urlFor($payment))['state'], 'JSON is never redirected');
    }

    public function test_a_code_shows_the_code_and_its_expiry_and_no_link(): void
    {
        $result = Fawaterk::checkout($this->order(), 'fawry');
        $payment = $this->payment($result->paymentUuid);

        $json = $this->jsonFor($this->urlFor($payment));

        $this->assertSame('awaiting_payment', $json['state']);
        $this->assertSame($payment->reference, $json['reference']);
        $this->assertNotNull($json['reference_expires_at']);
        $this->assertNull($json['checkout_url']);
        $this->visit($this->urlFor($payment))->assertSee('Reference code')->assertSee((string) $payment->reference);
    }

    public function test_states_the_payer_can_see(): void
    {
        $payment = $this->started();
        $url = $this->urlFor($payment);
        // No re-reads: the page shows each stored state as it is.
        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down'));

        $payment->forceFill(['status' => PaymentStatus::Pending])->save();
        $this->assertSame('processing', $this->jsonFor($url)['state']);
        $this->visit($url)->assertSee('http-equiv="refresh"', false);

        $payment->addFlag(Flag::FailureReported);
        $payment->save();
        $this->assertSame('not_completed', $this->jsonFor($url)['state']);
        $this->assertNull($this->jsonFor($url)['checkout_url'], 'a reported failure never offers the same link');

        $payment->forceFill(['status' => PaymentStatus::Expired])->save();
        $this->assertSame('expired', $this->jsonFor($url)['state']);

        $payment->forceFill(['status' => PaymentStatus::Failed, 'flags' => null])->save();
        $this->assertSame('failed', $this->jsonFor($url)['state']);

        $payment->forceFill(['status' => PaymentStatus::Created, 'expires_at' => Carbon::now()->subMinute()])->save();
        $this->assertSame('expired', $this->jsonFor($url)['state']);
        $this->assertNull($this->jsonFor($url)['checkout_url']);

        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_amount_minor' => 15000, 'paid_at' => Carbon::now()])->save();
        $this->assertSame('paid', $this->jsonFor($url)['state']);
        $this->assertTrue($this->jsonFor($url)['paid']);
        $this->visit($url)->assertDontSee('http-equiv="refresh"', false);

        $payment->addFlag(Flag::AmountMismatch);
        $payment->save();
        $this->assertSame('under_review', $this->jsonFor($url)['state']);
        $this->assertFalse($this->jsonFor($url)['paid'], 'an app must not unlock an underpaid or doubly paid order');
        $this->assertTrue($this->jsonFor($url)['received']);

        $payment->forceFill(['status' => PaymentStatus::Refunded, 'flags' => null])->save();
        $this->assertSame('refunded', $this->jsonFor($url)['state']);
        $this->assertFalse($this->jsonFor($url)['paid']);
        $this->assertTrue($this->jsonFor($url)['received']);
    }

    public function test_arabic_pages_are_right_to_left(): void
    {
        $payment = $this->started();
        $this->app->setLocale('ar');

        $this->visit($this->urlFor($payment))->assertSee('dir="rtl"', false)->assertSee('في انتظار الدفع');
        $this->visit('/fawaterk/result/x/1/y')->assertStatus(403)->assertSee('dir="rtl"', false)->assertSee('هذا الرابط غير صالح');
    }

    public function test_the_page_is_throttled_per_ip(): void
    {
        config()->set('fawaterk.results.rate_limit', 3);
        $payment = $this->started();
        $url = $this->urlFor($payment);

        foreach (range(1, 3) as $ignored) {
            $this->visit($url)->assertOk();
        }

        $this->visit($url)->assertStatus(429);
    }

    public function test_a_result_key_of_its_own_signs_the_urls(): void
    {
        $payment = $this->started();
        $derived = $this->urlFor($payment);

        config()->set('fawaterk.results.key', str_repeat('k', 32));
        $own = $this->urlFor($payment);

        $this->assertNotSame($this->parts($derived)[2], $this->parts($own)[2]);
        $this->visit($own)->assertOk();
        $this->visit($derived)->assertStatus(403);

        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $this->visit($own)->assertOk();

        config()->set('fawaterk.results.key', 'too-short');
        $this->assertRaises(fn () => $this->app->make(ResultSigner::class)->sign($payment->uuid, time()), ConfigurationException::class, '32 characters');
    }

    public function test_the_derived_key_is_not_app_key_itself(): void
    {
        $signer = $this->app->make(ResultSigner::class);
        $uuid = '5b1f0d2e-6f7a-4c1b-9a8e-0c2d3e4f5a6b';

        $this->assertNotSame(hash_hmac('sha256', 'fawaterk-result|v1|'.$uuid.'|1', (string) config('app.key')), $signer->sign($uuid, 1));
    }

    public function test_result_urls_last_past_the_due_date(): void
    {
        Fawaterk::checkout($this->order(), 'fawry');
        $sent = $this->fake->created()[0];
        [, $expires] = $this->parts($sent->toPayload()['redirectionUrls']['successUrl']);

        $this->assertGreaterThanOrEqual(Carbon::now()->addMinutes(2880)->addDays(30)->getTimestamp() - 5, (int) $expires);
    }

    public function test_page_re_reads_slow_down_after_a_first_burst(): void
    {
        config()->set('fawaterk.results.fast_rereads', 3);
        $payment = $this->started();
        $url = $this->urlFor($payment);

        foreach (range(1, 5) as $ignored) {
            $this->visit($url);
            Carbon::setTestNow(Carbon::now()->addSeconds(11));
        }

        $this->assertSame(3, $this->fake->getTransactionCalls(), 'every 10 s for the first 3 re-reads only');

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->visit($url);
        $this->assertSame(4, $this->fake->getTransactionCalls(), 'then every 5 minutes');

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->visit($url);
        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->visit($url);
        $this->assertSame(6, $this->fake->getTransactionCalls(), 'a day later the payer gets fast re-reads again');
    }

    public function test_a_pending_link_stops_saying_in_progress_and_stops_refreshing(): void
    {
        config()->set('fawaterk.results.fast_rereads', 2);
        $payment = $this->started();
        $payment->forceFill(['status' => PaymentStatus::Pending, 'fawaterk_transaction_id' => 91])->save();
        $url = $this->urlFor($payment);

        $this->visit($url)->assertSee('http-equiv="refresh"', false)->assertSee('Payment in progress');
        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->visit($url);

        $this->assertSame('unconfirmed', $this->jsonFor($url)['state']);
        $this->visit($url)->assertDontSee('http-equiv="refresh"', false)->assertSee('Payment not confirmed yet');
    }

    public function test_page_re_reads_are_capped_for_the_whole_account(): void
    {
        config()->set('fawaterk.results.rereads_per_minute', 4);
        // The cap counts per clock minute: keep every visit inside one.
        Carbon::setTestNow(Carbon::now()->startOfMinute()->addSeconds(5));
        $urls = array_map(fn () => $this->urlFor($this->started()), range(1, 7));

        foreach ($urls as $url) {
            $this->visit($url)->assertOk();
        }

        $this->assertSame(4, $this->fake->getTransactionCalls());
    }

    public function test_only_the_first_re_read_brings_reconciles_check_forward(): void
    {
        $payment = $this->started();
        $url = $this->urlFor($payment);

        $this->visit($url);
        $payment->refresh()->forceFill(['next_check_at' => Carbon::now()->addHour()])->save();
        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->visit($url);

        $this->assertSame(2, $this->fake->getTransactionCalls());
        $this->assertTrue($payment->refresh()->next_check_at->gt(Carbon::now()->addMinutes(50)), 'repeated visits do not override the back-off');
    }

    public function test_a_forwarded_prefix_changes_no_url(): void
    {
        Request::setTrustedProxies(['0.0.0.0/0'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PREFIX);

        try {
            $payment = $this->started();
            $url = $this->urlFor($payment);
            $path = substr($url, strlen('https://shop.test'));

            $response = $this->call('POST', $path, [], [], [], ['HTTP_X_FORWARDED_PREFIX' => '//evil.example']);
            $this->assertSame($url, $response->headers->get('Location'));

            $this->app['url']->setRequest(Request::create('https://shop.test/checkout', 'GET', [], [], [], ['HTTP_X_FORWARDED_PREFIX' => '/q|/result', 'REMOTE_ADDR' => '10.0.0.1']));
            $this->assertStringStartsWith('https://shop.test/fawaterk/result/'.$payment->uuid.'/', (string) Fawaterk::resultUrl($payment));
            $this->assertStringStartsWith('https://shop.test/fawaterk/webhooks/paid_json', (string) $this->app->make(WebhookUrls::class)->for(WebhookType::Paid));
        } finally {
            Request::setTrustedProxies([], -1);
        }
    }

    public function test_the_apps_own_route_parameters_named_payment_do_not_interfere(): void
    {
        $router = $this->app['router'];
        $router->setRoutes(new RouteCollection);
        $router->pattern('payment', '[0-9]+');
        $router->bind('payment', fn () => throw new \RuntimeException('the app binding ran'));
        $router->fawaterkWebhooks();
        $router->fawaterk('fawaterk', ['middleware' => [SubstituteBindings::class]]);

        $payment = $this->started();

        $this->visit($this->urlFor($payment))->assertOk()->assertSee('Waiting for your payment');
    }

    public function test_a_route_under_a_parameterised_prefix_fails_clearly(): void
    {
        $router = $this->app['router'];
        $router->setRoutes(new RouteCollection);
        $router->fawaterkWebhooks();
        $router->prefix('{locale}')->group(fn () => $router->fawaterk());

        $this->assertRaises(fn () => Fawaterk::checkout($this->order()), ConfigurationException::class, '{locale}');
    }

    public function test_back_and_redirect_urls_must_stay_on_allowed_hosts(): void
    {
        config()->set('fawaterk.return_url_hosts', ['partner.test']);
        $payment = $this->started();
        $url = $this->urlFor($payment);

        Fawaterk::resultBackUrlUsing(fn () => 'https://evil.example/phish');
        Fawaterk::resultRedirectUsing(fn () => 'https://evil.example/phish');
        $this->assertSame('https://shop.test/', $this->jsonFor($url)['back_url']);
        $this->visit($url)->assertOk();

        Fawaterk::resultBackUrlUsing(fn () => 'https://partner.test/orders');
        Fawaterk::resultRedirectUsing(fn () => 'https://SHOP.test/thanks');
        $this->assertSame('https://partner.test/orders', $this->jsonFor($url)['back_url']);
        $this->assertSame('https://SHOP.test/thanks', $this->visit($url)->headers->get('Location'));
    }

    public function test_the_page_shows_one_deadline_with_its_time_zone(): void
    {
        config()->set('fawaterk.results.timezone', 'Africa/Cairo');
        $payment = $this->payment(Fawaterk::checkout($this->order(), 'fawry')->paymentUuid);
        $deadline = Carbon::now()->addHours(3)->startOfMinute();
        $payment->forceFill(['expires_at' => $deadline, 'reference_expires_at' => Carbon::now()->addDays(2)])->save();

        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down'));
        $cairo = $deadline->copy()->setTimezone('Africa/Cairo');

        $this->visit($this->urlFor($payment))->assertSee($cairo->format('Y-m-d H:i').' (UTC'.$cairo->format('P').')');
        $this->assertSame($deadline->toAtomString(), Carbon::parse($this->jsonFor($this->urlFor($payment))['reference_expires_at'])->toAtomString());
    }

    private function started(): FawaterkPayment
    {
        return $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
    }

    private function urlFor(FawaterkPayment $payment): string
    {
        return (string) Fawaterk::resultUrl($payment);
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function parts(string $url): array
    {
        $segments = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));

        return [$segments[2], (int) $segments[3], $segments[4]];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function visit(string $url, array $headers = []): TestResponse
    {
        $path = str_starts_with($url, 'https://') ? substr($url, strlen('https://shop.test')) : $url;

        return $this->get($path, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFor(string $url): array
    {
        return (array) $this->visit($url, ['Accept' => 'application/json'])->assertOk()->json();
    }
}

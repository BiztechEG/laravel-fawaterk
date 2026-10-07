<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentLink;
use BiztechEG\Fawaterk\Data\PaymentData\ReferenceCode;
use BiztechEG\Fawaterk\Data\PaymentData\WalletRequest;
use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Exceptions\AlreadyPaidException;
use BiztechEG\Fawaterk\Exceptions\CheckoutInProgressException;
use BiztechEG\Fawaterk\Exceptions\CheckoutInTransactionException;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use BiztechEG\Fawaterk\Exceptions\ValidationException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\Fixtures\Order;
use BiztechEG\Fawaterk\Tests\Fixtures\RealClient;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class CheckoutTest extends LedgerTestCase
{
    public function test_a_hosted_checkout_creates_one_row_and_one_link(): void
    {
        $order = $this->order();

        $result = Fawaterk::checkout($order);

        $this->assertSame('link', $result->kind);
        $this->assertFalse($result->reused);
        $this->assertStringStartsWith('https://fawaterk.test/ts/', (string) $result->url);

        $payment = $this->payment($result->paymentUuid);
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertSame(15000, $payment->amount_minor);
        $this->assertSame('hosted', $payment->profile);
        $this->assertSame('default', $payment->purpose);
        $this->assertSame($order->getMorphClass(), $payment->payable_type);
        $this->assertNotNull($payment->intent_key);
        $this->assertNotNull($payment->expires_at);
        $this->assertNotNull($payment->next_check_at);

        $sent = $this->fake->created()[0]->toPayload();
        $this->assertSame('https://shop.test/fawaterk/webhooks/paid_json', $sent['redirectionUrls']['webhookUrl']);
        $this->assertArrayNotHasKey('payment_method_id', $sent);
        $this->assertArrayNotHasKey('redirectOption', $sent);
        $this->assertSame(['order' => $order->number], $sent['pay_load']);
    }

    public function test_a_second_checkout_reuses_the_live_link(): void
    {
        $order = $this->order();

        $first = Fawaterk::checkout($order);
        $second = $order->fawaterkCheckout();

        $this->assertTrue($second->reused);
        $this->assertSame($first->paymentUuid, $second->paymentUuid);
        $this->fake->assertCreatedCount(1);
    }

    public function test_a_new_link_after_pending_failed_expiry_or_an_order_change(): void
    {
        $order = $this->order();
        $first = Fawaterk::checkout($order);

        foreach ([PaymentStatus::Pending, PaymentStatus::Failed] as $status) {
            FawaterkPayment::query()->where('uuid', $first->paymentUuid)->update(['status' => $status->value]);
            $this->assertFalse(Fawaterk::checkout($order)->reused, $status->value);
            FawaterkPayment::query()->where('status', 'created')->update(['status' => 'failed']);
        }

        $this->travel(31)->days();
        $this->assertFalse(Fawaterk::checkout($order)->reused, 'an expired link is not reused');

        $order->update(['total_minor' => 20000]);
        $changed = Fawaterk::checkout($order);
        $this->assertFalse($changed->reused, 'a changed order gets a new link');
        $this->assertSame(20000, $this->payment($changed->paymentUuid)->amount_minor);
    }

    public function test_a_link_or_code_with_a_failure_report_is_not_reused(): void
    {
        foreach (['hosted', 'fawry'] as $profile) {
            $order = $this->order();
            $first = Fawaterk::checkout($order, $profile);
            $this->send(SignedWebhook::failed((string) $this->payment($first->paymentUuid)->intent_key));

            $again = Fawaterk::checkout($order, $profile);

            $this->assertFalse($again->reused, $profile);
            $this->assertNotSame($first->paymentUuid, $again->paymentUuid, $profile);
        }
    }

    public function test_a_code_profile_sends_the_method_and_returns_a_reference(): void
    {
        $result = Fawaterk::checkout($this->order(), 'fawry');

        $this->assertTrue($result->isCode());
        $this->assertMatchesRegularExpression('/^\d{9}$/', (string) $result->referenceNumber);
        $this->assertNull($result->url);

        $sent = $this->fake->created()[0]->toPayload();
        $this->assertSame(3, $sent['payment_method_id']);
        $this->assertFalse($sent['redirectOption']);
        $this->assertArrayHasKey('due_date', $sent);

        $payment = $this->payment($result->paymentUuid);
        $this->assertSame(3, $payment->payment_method_id);
        $this->assertSame($result->referenceNumber, $payment->reference);
        $this->assertSame(1, $this->fake->getTransactionCalls(), 'a direct dispatch is re-read at once');
    }

    public function test_a_card_profile_preselects_the_card_on_a_link(): void
    {
        $result = Fawaterk::checkout($this->order(), 'card');

        $this->assertSame('link', $result->kind);
        $sent = $this->fake->created()[0]->toPayload();
        $this->assertSame(2, $sent['payment_method_id']);
        $this->assertTrue($sent['redirectOption']);
    }

    public function test_a_code_is_reused_only_while_unpaid_with_time_left(): void
    {
        $order = $this->order();
        $first = Fawaterk::checkout($order, 'fawry');

        $again = Fawaterk::checkout($order, 'fawry');
        $this->assertTrue($again->reused);
        $this->assertSame($first->referenceNumber, $again->referenceNumber);

        // The fake's code expires in 2 days; with less than an hour left it is not reused.
        $this->travel(2)->days();
        $this->travel(-30)->minutes();
        $this->assertFalse(Fawaterk::checkout($order, 'fawry')->reused);
    }

    public function test_a_codes_validity_is_the_due_date_asked_for_not_the_pages_two_hours(): void
    {
        $this->fawryAnswer();

        $payment = $this->payment(Fawaterk::checkout($this->order(), 'fawry')->paymentUuid);

        $this->assertMinutesFromNow(2880, $payment->expires_at, 'the profile due_after, not expires_in');
        $this->assertMinutesFromNow(4 * 1440, $payment->reference_expires_at);

        // Per call: a shorter due date, sent to Fawaterk and recorded.
        $short = Fawaterk::checkout($this->order(), new CheckoutContext(profile: 'fawry', overrides: ['due_after' => 60]));
        $this->assertMinutesFromNow(60, $this->payment($short->paymentUuid)->expires_at);
        $this->assertMinutesFromNow(60, new Carbon($this->fake->created()[1]->toPayload()['due_date']));
    }

    public function test_a_codes_validity_can_be_its_own_expiry_globally_per_profile_or_per_call(): void
    {
        $this->fawryAnswer();
        config()->set('fawaterk.code_validity', 'code_expiry');

        $this->assertMinutesFromNow(4 * 1440, $this->payment(Fawaterk::checkout($this->order(), 'fawry')->paymentUuid)->expires_at);

        config()->set('fawaterk.profiles.fawry.code_validity', 'due_date');
        $this->assertMinutesFromNow(2880, $this->payment(Fawaterk::checkout($this->order(), 'fawry')->paymentUuid)->expires_at);

        $call = new CheckoutContext(profile: 'fawry', overrides: ['code_validity' => 'code_expiry']);
        $this->assertMinutesFromNow(4 * 1440, $this->payment(Fawaterk::checkout($this->order(), $call)->paymentUuid)->expires_at);
    }

    public function test_a_code_without_its_own_expiry_falls_back_to_the_due_date(): void
    {
        $this->fake->createTransactionUsing(fn () => new TransactionIntent('kd7rwmxqoltbv3ezsa', new ReferenceCode('712345678', null), 7200));
        config()->set('fawaterk.code_validity', 'code_expiry');

        $this->assertMinutesFromNow(2880, $this->payment(Fawaterk::checkout($this->order(), 'fawry')->paymentUuid)->expires_at);
    }

    public function test_a_link_still_ends_with_expires_in(): void
    {
        $this->fake->createTransactionUsing(fn () => new TransactionIntent('kd7rwmxqoltbv3ezsa', new PaymentLink('https://staging.fawaterk.com/ts/abcd2345'), 7200));
        config()->set('fawaterk.code_validity', 'code_expiry');

        $this->assertMinutesFromNow(120, $this->payment(Fawaterk::checkout($this->order(), new CheckoutContext(profile: 'hosted', overrides: ['due_after' => 2880]))->paymentUuid)->expires_at);
    }

    public function test_an_unknown_code_validity_is_a_configuration_error(): void
    {
        foreach ([fn () => config()->set('fawaterk.code_validity', 'forever'), fn () => config()->set('fawaterk.profiles.fawry.code_validity', 2)] as $set) {
            $set();
            $this->assertRaises(fn () => Fawaterk::checkout($this->order(), 'fawry'), ConfigurationException::class);
            config()->set('fawaterk.code_validity', 'due_date');
            config()->set('fawaterk.profiles.fawry.code_validity', null);
        }

        $this->fake->assertNothingCreated();
    }

    public function test_a_code_past_its_due_date_is_not_reused_while_it_could_still_be_paid(): void
    {
        $this->fawryAnswer();
        $order = $this->order();
        $first = Fawaterk::checkout($order, 'fawry');

        // Two days on, the code still has two days at Fawry, but the due date asked for has passed.
        $this->travel(2)->days();

        $again = Fawaterk::checkout($order, 'fawry');
        $this->assertFalse($again->reused);
        $this->assertNotSame($first->paymentUuid, $again->paymentUuid);
    }

    /** Fawaterk's answer for a Fawry code: expires_in two hours, the code itself four days. */
    private function fawryAnswer(): void
    {
        $this->fake->createTransactionUsing(fn () => new TransactionIntent(
            Str::lower(Str::random(18)),
            new ReferenceCode((string) random_int(100000000, 999999999), new \DateTimeImmutable('+4 days')),
            7200,
        ));
    }

    private function assertMinutesFromNow(int $minutes, ?\DateTimeInterface $at, string $message = ''): void
    {
        $this->assertNotNull($at, $message);
        $this->assertEqualsWithDelta(Carbon::now()->addMinutes($minutes)->getTimestamp(), $at->getTimestamp(), 90, $message);
    }

    public function test_reusing_a_code_that_was_paid_meanwhile_records_the_payment(): void
    {
        Event::fake([PaymentPaid::class]);
        $order = $this->order();
        $first = Fawaterk::checkout($order, 'fawry');
        $this->fake->markPaid((string) $this->payment($first->paymentUuid)->intent_key, transactionId: 555);

        $this->assertRaises(fn () => Fawaterk::checkout($order, 'fawry'), AlreadyPaidException::class);

        Event::assertDispatched(PaymentPaid::class);
        $this->fake->assertCreatedCount(1);
    }

    public function test_a_paid_purpose_is_never_charged_again_but_other_purposes_are(): void
    {
        $order = $this->order();
        $deposit = Fawaterk::checkout($order, new CheckoutContext('hosted', 'deposit'));
        $this->fake->markPaid((string) $this->payment($deposit->paymentUuid)->intent_key);
        FawaterkPayment::query()->where('uuid', $deposit->paymentUuid)->update(['status' => 'paid']);

        $this->assertRaises(fn () => Fawaterk::checkout($order, new CheckoutContext('hosted', 'deposit')), AlreadyPaidException::class);

        $balance = Fawaterk::checkout($order, new CheckoutContext('hosted', 'balance'));
        $this->assertNotSame($deposit->paymentUuid, $balance->paymentUuid);
        $this->assertSame(7500, $this->payment($deposit->paymentUuid)->amount_minor);
        $this->assertSame(15000, $this->payment($balance->paymentUuid)->amount_minor);
    }

    public function test_failed_creations_leave_a_failed_row_with_the_reason(): void
    {
        $cases = [
            'create_rejected' => new ValidationException('Invalid method', 422),
            'create_outcome_unknown' => new ServiceUnavailableException('Timed out', 0, outcomeUnknown: true),
            'create_failed' => new ServiceUnavailableException('Refused', 0, outcomeUnknown: false),
        ];

        foreach ($cases as $reason => $exception) {
            $this->fake->createTransactionUsing(fn () => throw $exception);

            $this->assertRaises(fn () => Fawaterk::checkout($this->order()), get_class($exception));
            $this->assertSame($reason, FawaterkPayment::query()->latest('id')->firstOrFail()->failure_reason);
            $this->assertSame(PaymentStatus::Failed, FawaterkPayment::query()->latest('id')->firstOrFail()->status);
        }
    }

    public function test_a_payment_kind_this_version_does_not_handle_fails_closed(): void
    {
        $this->fake->createTransactionUsing(fn () => new TransactionIntent((string) Str::uuid(), new WalletRequest('4266311', null)));

        $this->assertRaises(fn () => Fawaterk::checkout($this->order()), UnexpectedResponseException::class);
        $this->assertSame('unexpected_response', FawaterkPayment::query()->firstOrFail()->failure_reason);
    }

    public function test_return_urls_must_be_on_an_allowed_host(): void
    {
        $allowed = new CheckoutContext('hosted', overrides: ['return_urls' => ['success' => 'https://shop.test/thanks']]);
        Fawaterk::checkout($this->order(), $allowed);
        $this->assertSame('https://shop.test/thanks', $this->fake->created()[0]->toPayload()['redirectionUrls']['successUrl']);

        $elsewhere = new CheckoutContext('hosted', overrides: ['return_urls' => ['success' => 'https://evil.test/phish']]);
        $this->assertRaises(fn () => Fawaterk::checkout($this->order(), $elsewhere), InvalidRequestException::class, 'allowed host');

        config()->set('fawaterk.return_url_hosts', ['partner.test']);
        Fawaterk::checkout($this->order(), new CheckoutContext('hosted', overrides: ['return_urls' => ['back' => 'https://partner.test/back']]));
        $this->addToAssertionCount(1);
    }

    public function test_bad_input_and_config_are_refused_before_anything_is_created(): void
    {
        $this->assertRaises(fn () => Fawaterk::checkout(new Order(['number' => 'X', 'total_minor' => 100, 'user_id' => 1])), InvalidRequestException::class, 'Save the payable');
        $this->assertRaises(fn () => Fawaterk::checkout($this->order(), 'unknown'), ConfigurationException::class, 'not configured');
        $this->assertRaises(fn () => new CheckoutContext('hosted', 'bad purpose!'), InvalidRequestException::class);
        $this->assertRaises(fn () => new CheckoutContext('hosted', overrides: ['amount' => 1]), InvalidRequestException::class);

        config()->set('app.url', 'http://shop.test');
        $this->assertRaises(fn () => Fawaterk::checkout($this->order()), ConfigurationException::class, 'https');

        $this->fake->assertNothingCreated();
    }

    public function test_the_app_cannot_choose_the_method_or_the_urls(): void
    {
        $order = new class(['number' => 'B-1', 'total_minor' => 5000, 'user_id' => 1]) extends Order
        {
            protected $table = 'orders';

            public function toFawaterkCheckout(CheckoutContext $context): CreateTransaction
            {
                return parent::toFawaterkCheckout($context)->with([
                    'paymentMethodId' => 99,
                    'redirectOption' => false,
                    'redirectionUrls' => new RedirectionUrls(webhookUrl: 'https://evil.test/hook'),
                ]);
            }
        };
        $order->save();

        Fawaterk::checkout($order);

        $sent = $this->fake->created()[0]->toPayload();
        $this->assertArrayNotHasKey('payment_method_id', $sent);
        $this->assertSame('https://shop.test/fawaterk/webhooks/paid_json', $sent['redirectionUrls']['webhookUrl']);
    }

    public function test_a_real_checkout_inside_a_db_transaction_is_refused(): void
    {
        // The fake is exempt, so apps' tests that wrap everything in a transaction keep working.
        DB::transaction(fn () => Fawaterk::checkout($this->order()));
        $this->fake->assertCreatedCount(1);

        $this->app->instance(FawaterkClient::class, new RealClient($this->fake));
        $rows = FawaterkPayment::query()->count();

        $this->assertRaises(
            fn () => DB::transaction(fn () => Fawaterk::checkout($this->order(), 'card')),
            CheckoutInTransactionException::class,
        );
        $this->fake->assertCreatedCount(1);
        $this->assertSame($rows, FawaterkPayment::query()->count());

        config()->set('fawaterk.cache_store', 'file'); // a store whose locks work across processes
        Fawaterk::checkout($this->order(), 'card'); // outside a transaction: fine
        $this->fake->assertCreatedCount(2);
    }

    public function test_cache_stores_whose_locks_protect_nothing_are_refused(): void
    {
        config()->set('cache.stores.none', ['driver' => 'null']);
        $this->app->instance(FawaterkClient::class, new RealClient($this->fake));

        // array: its locks only work inside one process.
        $this->assertRaises(fn () => Fawaterk::checkout($this->order()), ConfigurationException::class, 'locks');

        // null: every lock is granted.
        config()->set('fawaterk.cache_store', 'none');
        $this->assertRaises(fn () => Fawaterk::checkout($this->order()), ConfigurationException::class, 'locks');

        $this->fake->assertNothingCreated();
    }

    public function test_the_fake_works_with_any_store_for_apps_tests(): void
    {
        config()->set('cache.stores.none', ['driver' => 'null']);

        foreach (['array', 'none'] as $store) {
            config()->set('fawaterk.cache_store', $store);
            Fawaterk::checkout($this->order());
        }

        $this->fake->assertCreatedCount(2);
    }

    public function test_a_concurrent_checkout_for_the_same_purpose_is_refused(): void
    {
        $order = $this->order();
        $lock = Cache::lock("fawaterk:checkout:default:staging:{$order->getMorphClass()}:{$order->getKey()}:default", 60);
        $lock->get();

        config()->set('fawaterk.checkout_lock_wait', 1);
        $started = microtime(true);

        try {
            $this->assertRaises(fn () => Fawaterk::checkout($order), CheckoutInProgressException::class);
        } finally {
            $lock->release();
        }

        $this->assertLessThan(5, microtime(true) - $started);
        $this->fake->assertNothingCreated();
    }

    public function test_a_link_whose_result_url_is_about_to_lapse_is_not_reused(): void
    {
        $this->app['router']->fawaterk();
        config()->set('fawaterk.results.valid_days', 7);
        $order = $this->order();
        $first = Fawaterk::checkout($order);

        try {
            Carbon::setTestNow(Carbon::now()->addDays(5));
            $this->assertSame($first->paymentUuid, Fawaterk::checkout($order)->paymentUuid, 'two days left on its result URL');

            Carbon::setTestNow(Carbon::now()->addDay()->addHour());
            $third = Fawaterk::checkout($order);
            $this->assertFalse($third->reused, 'the payer would come back to an expired result URL');
            $this->fake->assertCreatedCount(2);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function send(SignedWebhook $webhook): void
    {
        $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson())->assertOk();
    }
}

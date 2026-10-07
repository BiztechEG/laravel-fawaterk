<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Events\PaymentCancelReported;
use BiztechEG\Fawaterk\Events\PaymentFailureReported;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentRefunded;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\UnknownPaymentPaid;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Http\Controllers\WebhookController;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Support\SafeLog;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\Fixtures\StrictCsrf;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use Illuminate\Routing\RouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Psr\Log\AbstractLogger;

class WebhookTest extends LedgerTestCase
{
    public function test_a_paid_webhook_is_re_read_and_pays_the_row(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, transactionId: 555);

        $this->send(SignedWebhook::paid((string) $payment->intent_key, 555))->assertOk()->assertSee('OK');

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(555, $payment->fawaterk_transaction_id);
        Event::assertDispatched(PaymentPaid::class);
        $this->assertLogged('paid', 'accepted', $payment->intent_key);
    }

    public function test_form_bodies_work_too(): void
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);

        $this->call('POST', '/fawaterk/webhooks/paid', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], SignedWebhook::paid((string) $payment->intent_key)->toForm())
            ->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function test_a_signed_pending_webhook_edited_to_paid_pays_nothing(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();

        $this->send(SignedWebhook::paid((string) $payment->intent_key)->with(['status' => 'paid', 'paidAmount' => 150]))->assertOk();

        $this->assertNotSame(PaymentStatus::Paid, $payment->refresh()->status);
        Event::assertNotDispatched(PaymentPaid::class);
    }

    public function test_bad_or_malformed_webhooks_get_401_and_cause_no_outbound_call(): void
    {
        $payment = $this->started();
        $valid = SignedWebhook::paid((string) $payment->intent_key)->toArray();
        $before = $this->fake->getTransactionCalls();

        $this->postJson('/fawaterk/webhooks/paid_json', SignedWebhook::paid((string) $payment->intent_key)->signedWith('wrong-key')->toArray())->assertStatus(401);
        $this->postJson('/fawaterk/webhooks/paid_json', ['transaction_id' => 1] + $valid)->assertStatus(401);
        $this->postJson('/fawaterk/webhooks/paid_json', array_diff_key($valid, ['transactionHashKey' => 1]))->assertStatus(401);
        $this->call('POST', '/fawaterk/webhooks/paid_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertStatus(401);
        $this->call('POST', '/fawaterk/webhooks/paid_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":"'.str_repeat('x', 70000).'"}')->assertStatus(413);

        $this->assertSame($before, $this->fake->getTransactionCalls());
        $this->assertSame(PaymentStatus::Created, $payment->refresh()->status);
        $this->assertSame(0, WebhookEvent::query()->count(), 'unverified webhooks write no rows (a flood cannot fill the table)');
        $this->assertSame(5, Cache::get(WebhookController::rejectedCountKey('default', 'staging')), 'they are counted per day instead');
    }

    public function test_a_form_body_with_too_many_fields_is_refused_not_a_500(): void
    {
        $body = http_build_query(array_fill_keys(array_map(fn ($i) => "f{$i}", range(1, 1500)), '1'));

        $this->call('POST', '/fawaterk/webhooks/paid', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)
            ->assertStatus(401);
    }

    public function test_unknown_routes_and_accounts_are_404(): void
    {
        $this->postJson('/fawaterk/webhooks/other_json', [])->assertNotFound();
        $this->postJson('/fawaterk/webhooks/paid_json/second', [])->assertNotFound();
    }

    public function test_invoice_payloads_from_other_integrations_are_answered_200(): void
    {
        $this->send(SignedWebhook::invoice())->assertOk();
        $this->assertLogged('paid', 'foreign');

        $this->postJson('/fawaterk/webhooks/paid_json', SignedWebhook::invoice()->signedWith('wrong-key')->toArray())->assertStatus(401);
    }

    public function test_an_unknown_intent_is_200_and_raised_when_paid(): void
    {
        Event::fake([UnknownPaymentPaid::class]);
        $foreign = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

        $this->send(SignedWebhook::paid($foreign))->assertOk();
        Event::assertNotDispatched(UnknownPaymentPaid::class);

        $this->fake->getTransactionUsing(fn (string $key) => new TransactionData($key, 99, true, 100, 'EGP', null, 'Fawry', 'paid', null));
        $other = '7ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $this->send(SignedWebhook::paid($other, 99))->assertOk();

        Event::assertDispatched(UnknownPaymentPaid::class, fn ($event) => $event->intentKey === $other && $event->transactionId === 99);
        $this->assertLogged('paid', 'unknown_payment', $other);
    }

    public function test_503_when_the_re_read_cannot_reach_fawaterk(): void
    {
        $payment = $this->started();
        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down', 0));

        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertStatus(503);

        $this->assertSame(PaymentStatus::Created, $payment->refresh()->status);
    }

    public function test_an_already_paid_row_is_not_re_read_again(): void
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();
        $calls = $this->fake->getTransactionCalls();

        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertSame($calls, $this->fake->getTransactionCalls());
        $this->assertLogged('paid', 'duplicate', $payment->intent_key);
    }

    public function test_a_pending_then_a_paid_webhook_are_both_re_read(): void
    {
        $payment = $this->started();

        $this->send(SignedWebhook::paid((string) $payment->intent_key)->with(['status' => 'pending']))->assertOk();
        $this->fake->markPaid((string) $payment->intent_key);
        $this->travel(11)->seconds();
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'no cooldown for the paid URL on an unpaid row');
    }

    public function test_paid_webhooks_for_one_intent_re_read_at_most_every_ten_seconds(): void
    {
        $payment = $this->started();
        $payment->forceFill(['next_check_at' => now()->addHour()])->save();
        $calls = $this->fake->getTransactionCalls();

        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();
        $this->fake->markPaid((string) $payment->intent_key);
        foreach (range(1, 5) as $replay) {
            $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk()->assertSee('OK');
        }

        $this->assertSame($calls + 1, $this->fake->getTransactionCalls(), 'one re-read for the burst');
        $this->assertLogged('paid', 'deferred', $payment->intent_key);
        $payment->refresh();
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertTrue($payment->next_check_at->lte(now()->addMinute()), 'the deferred one is re-checked soon');

        $this->travel(11)->seconds();
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function test_a_failed_webhook_is_only_a_re_check_trigger(): void
    {
        Event::fake([PaymentFailureReported::class]);
        $payment = $this->started();

        $this->send(SignedWebhook::failed((string) $payment->intent_key, 777))->assertOk();
        $this->send(SignedWebhook::failed((string) $payment->intent_key, 777))->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Created, $payment->status, 'a failed webhook never changes the status');
        $this->assertTrue($payment->hasFlag(Flag::FailureReported));
        $this->assertSame(777, $payment->fawaterk_transaction_id);
        $this->assertNotNull($payment->next_check_at);
        Event::assertDispatchedTimes(PaymentFailureReported::class, 1);
        Event::assertDispatched(PaymentFailureReported::class, fn ($event) => $event->payment->is($payment) && $event->transactionId === 777);
        $this->assertLogged('failed', 'duplicate', $payment->intent_key);

        // After the cooldown it is handled again, but the flag is not new: no second event.
        $this->travel(2)->minutes();
        $this->send(SignedWebhook::failed((string) $payment->intent_key, 777))->assertOk();
        Event::assertDispatchedTimes(PaymentFailureReported::class, 1);

        // A later paid webhook pays it, and it is not a late payment.
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertFalse($payment->hasFlag(Flag::LatePayment));
    }

    public function test_a_captured_pending_webhook_replayed_as_failed_changes_no_status(): void
    {
        $payment = $this->started();
        $this->fake->getTransactionUsing(fn (string $key) => new TransactionData($key, 555, false, 15000, 'EGP', null, 'Fawry', 'pending', null));
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 555)->with(['status' => 'pending']))->assertOk();
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        // The failed URL takes the same signed string.
        $this->send(SignedWebhook::failed((string) $payment->intent_key, 555))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_the_failed_re_read_can_be_switched_off(): void
    {
        Event::fake([PaymentFailureReported::class]);
        config()->set('fawaterk.reread.failed', false);
        $payment = $this->started();
        $calls = $this->fake->getTransactionCalls();

        $this->send(SignedWebhook::failed((string) $payment->intent_key, 777))->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertTrue($payment->hasFlag(Flag::FailureReported));
        $this->assertSame(777, $payment->fawaterk_transaction_id);
        $this->assertTrue($payment->next_check_at->lte(now()->addMinutes(2)), 'reconcile re-reads it soon');
        $this->assertSame($calls, $this->fake->getTransactionCalls());
        Event::assertDispatched(PaymentFailureReported::class);
    }

    public function test_a_cancel_only_schedules_a_re_check(): void
    {
        Event::fake([PaymentCancelReported::class]);
        $payment = $this->started();
        $victim = $this->started();

        // The transactionKey is unsigned: pointing it at another row only re-checks that row.
        $this->send(SignedWebhook::cancel(4266311, 'Aman', (string) $victim->intent_key))->assertOk();
        $this->send(SignedWebhook::cancel(4266311, 'Aman', (string) $payment->intent_key))->assertOk();

        $victim->refresh();
        $this->assertSame(PaymentStatus::Created, $victim->status, 'no state change, ever');
        $this->assertTrue($victim->hasFlag(Flag::CancelReported));
        $this->assertTrue($victim->next_check_at->lte(now()));
        $this->assertFalse($payment->refresh()->hasFlag(Flag::CancelReported), 'the replay was deduplicated');
        Event::assertDispatchedTimes(PaymentCancelReported::class, 1);
        $this->assertLogged('cancel', 'duplicate');
    }

    public function test_a_refund_is_verified_against_the_refund_list(): void
    {
        Event::fake([PaymentRefunded::class, PaymentRefundReported::class]);
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [
            new RefundItem(10, '3', 555, 5000, 'approved'),
            new RefundItem(11, '0', 555, 10000, 'approved'),   // an invoice refund with the same id
            new RefundItem(12, '3', 556, 10000, 'approved'),   // another transaction
        ]));

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $payment->refresh();
        $this->assertSame(5000, $payment->refunded_amount_minor);
        $this->assertSame(PaymentStatus::Paid, $payment->status, 'a partial refund keeps it paid');
        $this->assertTrue($payment->hasFlag(Flag::Refunded));

        // A replay (after the cooldown) adds nothing.
        $this->travel(2)->minutes();
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $this->assertSame(5000, $payment->refresh()->refunded_amount_minor);

        $this->fake->setRefundPage(new RefundPage(1, 1, [
            new RefundItem(10, '3', 555, 5000, 'approved'),
            new RefundItem(13, '3', 555, 10000, 'approved'),
        ]));
        $this->travel(2)->minutes(); // one scan per payment per minute
        $this->send(SignedWebhook::refund(555, '100.00'))->assertOk();

        $payment->refresh();
        $this->assertSame(15000, $payment->refunded_amount_minor);
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
        Event::assertNotDispatched(PaymentRefundReported::class);
    }

    public function test_the_refund_re_read_can_be_switched_off(): void
    {
        config()->set('fawaterk.reread.refund', false);
        Event::fake([PaymentRefunded::class, PaymentRefundReported::class]);
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(10, '3', 555, 5000, 'approved')]));

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        $this->assertSame(0, $payment->refresh()->refunded_amount_minor);
        Event::assertDispatched(PaymentRefundReported::class);
    }

    public function test_the_route_is_csrf_free_throttled_and_named(): void
    {
        $route = Route::getRoutes()->getByName('fawaterk.webhooks');

        $this->assertNotNull($route);
        $this->assertContains('throttle:fawaterk-webhooks', $route->middleware());
        $this->assertContains('Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', $route->excludedMiddleware());
        $this->assertSame(['POST'], $route->methods());
    }

    public function test_a_real_post_passes_an_apps_csrf_middleware(): void
    {
        Route::middleware([StartSession::class, StrictCsrf::class])->group(function () {
            Route::fawaterkWebhooks('csrf/fawaterk', ['name' => 'csrf.fawaterk']);
            Route::post('csrf/control', fn () => 'reached');
        });
        Route::getRoutes()->refreshNameLookups();

        $this->post('/csrf/control')->assertStatus(419);

        $payment = $this->started();
        $this->call('POST', '/csrf/fawaterk/paid_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], SignedWebhook::paid((string) $payment->intent_key)->toJson())
            ->assertOk();
    }

    public function test_the_webhook_url_is_found_under_any_prefix_or_name(): void
    {
        $this->app['router']->setRoutes(new RouteCollection);
        Route::name('shop.')->prefix('api')->group(fn () => Route::fawaterkWebhooks('hooks/fawaterk', ['name' => 'fw']));
        Route::getRoutes()->refreshNameLookups();

        Fawaterk::checkout($this->order());

        $this->assertNotNull(Route::getRoutes()->getByName('shop.fw'));
        $this->assertSame('https://shop.test/api/hooks/fawaterk/paid_json', $this->fake->created()[0]->toPayload()['redirectionUrls']['webhookUrl']);
    }

    public function test_without_the_route_the_dashboard_webhook_is_used(): void
    {
        $this->app['router']->setRoutes(new RouteCollection);

        Fawaterk::checkout($this->order());

        $this->assertArrayNotHasKey('redirectionUrls', $this->fake->created()[0]->toPayload());
    }

    public function test_logs_hold_metadata_only(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            // Untyped $message: compatible with psr/log 1, 2 and 3.
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $this->app->instance(SafeLog::class, new SafeLog($logger));

        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key)->with([
            'customerData' => ['email' => 'buyer@example.test', 'phone' => '01000000000'],
            'api_key' => 'leaked-api-key',
        ]))->assertOk();

        $dump = json_encode($logger->records);
        $this->assertNotSame('[]', $dump);
        foreach (['buyer@example.test', '01000000000', 'leaked-api-key', 'test-vendor-key', (string) $payment->intent_key] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dump);
        }
    }

    private function started(): FawaterkPayment
    {
        return $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
    }

    private function paidPayment(int $transactionId): FawaterkPayment
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, transactionId: $transactionId);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, $transactionId))->assertOk();

        return $payment->refresh();
    }

    private function send(SignedWebhook $webhook): TestResponse
    {
        return $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
    }

    private function assertLogged(string $type, string $outcome, ?string $intentKey = null): void
    {
        $query = WebhookEvent::query()->where('type', $type)->where('outcome', $outcome);

        if ($intentKey !== null) {
            $query->where('intent_key', $intentKey);
        }

        $this->assertTrue($query->exists(), "No {$type} webhook logged as {$outcome}.");
    }
}

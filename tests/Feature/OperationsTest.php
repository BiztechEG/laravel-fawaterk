<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentOrderChanged;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\PaymentUnfulfilled;
use BiztechEG\Fawaterk\Exceptions\CheckoutInProgressException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\Fixtures\Order;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Crashes, and a local or staging process pointed at the live database.
 */
class OperationsTest extends LedgerTestCase
{
    public function test_a_blocking_alert_survives_a_crash_before_it_is_sent(): void
    {
        $pendingWhileSending = null;
        Event::listen(PaymentAmountMismatch::class, function (PaymentAmountMismatch $event) use (&$pendingWhileSending) {
            $pendingWhileSending ??= FawaterkPayment::query()->whereKey($event->payment->getKey())->value('next_alert_at');
        });
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, totalMinor: 100);

        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertNotNull($pendingWhileSending, 'committed with the flag, before the event is sent');
        $payment->refresh();
        $this->assertTrue($payment->hasFlag(Flag::AmountMismatch));
        $this->assertSame(15000, $payment->expected_amount_minor);
        $this->assertNull($payment->next_alert_at, 'cleared once sent');
    }

    public function test_an_unconfirmed_refund_is_committed_before_it_is_reported(): void
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, transactionId: 555);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 555))->assertOk();
        $whileSending = null;
        Event::listen(PaymentRefundReported::class, function () use ($payment, &$whileSending) {
            $whileSending ??= FawaterkPayment::query()->whereKey($payment->getKey())->first(['next_alert_at', 'refund_pending']);
        });

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        foreach (range(1, 75) as $step) {
            $this->travel(5)->minutes();
            $this->reconcile();
        }

        $this->assertNotNull($whileSending?->next_alert_at, 'the marker was committed before the event went out');
        $this->assertTrue((bool) ($whileSending->refund_pending[0]['report'] ?? false), 'and the report too');
        $payment->refresh();
        $this->assertNull($payment->next_alert_at);
        $this->assertNull($payment->refund_pending);
    }

    public function test_a_newer_alert_marker_is_not_wiped(): void
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, totalMinor: 100);
        // While our alert goes out, another process commits an alert of its own and dies before sending it.
        Event::listen(PaymentAmountMismatch::class, function (PaymentAmountMismatch $event) {
            FawaterkPayment::query()->whereKey($event->payment->getKey())->update(['next_alert_at' => now()->addMinutes(30)]);
        });

        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertNotNull($payment->refresh()->next_alert_at, 'its marker survives, so reconcile still sends its alert');
    }

    public function test_reconcile_sends_alerts_a_crash_left_unsent(): void
    {
        Event::fake([PaymentPaid::class, PaymentAmountMismatch::class, PaymentOrderChanged::class]);
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, totalMinor: 100);
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();
        Event::assertDispatchedTimes(PaymentAmountMismatch::class, 1);

        // The process died after the commit, before the event was sent.
        $payment->forceFill(['next_alert_at' => now()->subMinute()])->save();

        $this->reconcile();
        $this->reconcile();

        Event::assertDispatchedTimes(PaymentAmountMismatch::class, 2);
        Event::assertDispatched(PaymentAmountMismatch::class, fn ($event) => $event->expectedMinor === 15000 && $event->paidMinor === 100);
        Event::assertNotDispatched(PaymentPaid::class);
        Event::assertNotDispatched(PaymentOrderChanged::class);
        $this->assertNull($payment->refresh()->next_alert_at);
    }

    public function test_the_unfulfilled_alert_survives_a_crash_too(): void
    {
        Event::fake([PaymentPaid::class, PaymentUnfulfilled::class]);
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        foreach (range(1, 4) as $run) {
            $this->travel(20)->minutes();
            $this->reconcile();
        }
        Event::assertDispatchedTimes(PaymentUnfulfilled::class, 1);

        $payment->refresh()->forceFill(['next_alert_at' => now()->subMinute()])->save();
        $this->reconcile();
        $this->reconcile();

        Event::assertDispatchedTimes(PaymentUnfulfilled::class, 2);
        $this->assertNull($payment->refresh()->next_alert_at);
    }

    public function test_rows_of_the_other_environment_are_never_touched(): void
    {
        Event::fake([PaymentPaid::class]);
        $order = $this->order();
        $live = $this->started($order, 'fawry');
        $live->forceFill(['environment' => 'live', 'next_check_at' => now()->subMinute()])->save();
        $this->fake->markPaid((string) $live->intent_key);

        // A staging process (this app) on the live database: to it, the live
        // row does not exist.
        $this->send(SignedWebhook::paid((string) $live->intent_key))->assertOk();
        $this->assertTrue(WebhookEvent::query()->where('outcome', 'unknown_payment')->exists());

        $calls = $this->fake->getTransactionCalls();
        $this->travel(3)->days();
        app(Reconciler::class)->run();
        $this->artisan('fawaterk:simulate', ['type' => 'paid', 'payment' => $live->uuid, '--force' => true])->assertExitCode(1);

        $live->refresh();
        $this->assertSame(PaymentStatus::Created, $live->status, 'not paid, not expired');
        $this->assertSame($calls, $this->fake->getTransactionCalls(), 'reconcile never re-read it');
        Event::assertNotDispatched(PaymentPaid::class);

        // A checkout here neither reuses nor refuses because of the live row.
        $this->assertFalse(Fawaterk::checkout($order, 'fawry')->reused);
    }

    public function test_locks_and_the_heartbeat_are_kept_per_environment(): void
    {
        $order = $this->order();
        config()->set('fawaterk.checkout_lock_wait', 1);
        $lock = Cache::lock("fawaterk:checkout:default:staging:{$order->getMorphClass()}:{$order->getKey()}:default", 60);
        $lock->get();

        try {
            $this->assertRaises(fn () => Fawaterk::checkout($order), CheckoutInProgressException::class);
        } finally {
            $lock->release();
        }

        $running = Cache::lock('fawaterk:default:staging:reconcile:running', 60);
        $running->get();

        try {
            $this->artisan('fawaterk:reconcile')->expectsOutputToContain('Another fawaterk:reconcile is running')->assertExitCode(0);
        } finally {
            $running->release();
        }

        $this->artisan('fawaterk:reconcile')->assertExitCode(0);
        $this->assertIsInt(Cache::get('fawaterk:default:staging:reconcile:heartbeat'));
    }

    private function reconcile(): void
    {
        app(Reconciler::class)->run();
    }

    private function started(?Order $order = null, string $profile = 'hosted'): FawaterkPayment
    {
        return $this->payment(Fawaterk::checkout($order ?? $this->order(), $profile)->paymentUuid);
    }

    private function send(SignedWebhook $webhook): TestResponse
    {
        return $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
    }
}

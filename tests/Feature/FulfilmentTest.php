<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Events\PaymentOrderChanged;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentUnfulfilled;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\Fixtures\Order;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Illuminate\Support\Facades\Event;
use LogicException;
use RuntimeException;

/**
 * PaymentPaid is sent at least once, and only for what was paid for.
 */
class FulfilmentTest extends LedgerTestCase
{
    public function test_an_order_edited_after_payment_is_not_delivered_as_edited(): void
    {
        Event::fake([PaymentPaid::class, PaymentOrderChanged::class, PaymentUnfulfilled::class]);
        $order = $this->order();
        $payment = $this->paidPayment($order);
        Event::assertDispatchedTimes(PaymentPaid::class, 1);

        // Not fulfilled yet; the order is edited before the re-send.
        $order->update(['total_minor' => 99900]);
        $this->travel(15)->minutes();
        $this->reconcile();

        $payment->refresh();
        $this->assertTrue($payment->hasFlag(Flag::OrderChanged));
        $this->assertNull($payment->next_dispatch_at, 'settled');
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
        Event::assertDispatched(PaymentOrderChanged::class, fn ($event) => ! $event->payableMissing);
        Event::assertNotDispatched(PaymentUnfulfilled::class);
    }

    public function test_a_payable_deleted_after_payment_gets_no_re_send(): void
    {
        Event::fake([PaymentPaid::class, PaymentOrderChanged::class]);
        $order = $this->order();
        $payment = $this->paidPayment($order);

        $order->delete();
        $this->travel(15)->minutes();
        $this->reconcile();

        $this->assertTrue($payment->refresh()->hasFlag(Flag::PayableMissing));
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
        Event::assertDispatched(PaymentOrderChanged::class, fn ($event) => $event->payableMissing);
    }

    public function test_fulfil_once_runs_the_delivery_once_however_often_paid_is_sent(): void
    {
        $delivered = 0;
        Event::listen(PaymentPaid::class, function (PaymentPaid $event) use (&$delivered) {
            $event->payment->fulfilOnce(function (FawaterkPayment $payment) use (&$delivered) {
                $delivered++;
                Order::query()->whereKey($payment->payable_id)->update(['status' => 'delivered']);
            });
        });
        $order = $this->order();
        $payment = $this->paidPayment($order);

        // A queue retry or a second worker sends it again.
        $stale = $payment->fresh();
        $stale->forceFill(['fulfilled_at' => null])->syncOriginal();
        Event::dispatch(new PaymentPaid($stale));

        $this->assertSame(1, $delivered);
        $this->assertSame('delivered', $order->refresh()->status);
        $this->assertNotNull($payment->refresh()->fulfilled_at);
    }

    public function test_fulfil_once_rolls_back_with_the_delivery(): void
    {
        $payment = $this->paidPayment($order = $this->order());

        $this->assertRaises(fn () => $payment->fulfilOnce(function () use ($order) {
            $order->update(['status' => 'delivered']);

            throw new RuntimeException('stock service down');
        }), RuntimeException::class);

        $this->assertSame('new', $order->refresh()->status, 'the delivery rolled back');
        $this->assertNull($payment->refresh()->fulfilled_at);
        $this->assertTrue($payment->fulfilOnce(fn () => null), 'it can run again');
        $this->assertFalse($payment->fulfilOnce(fn () => $this->fail('ran twice')));
    }

    public function test_fulfil_once_refuses_an_order_edited_after_payment(): void
    {
        Event::fake([PaymentPaid::class, PaymentOrderChanged::class]);
        $order = $this->order();
        $payment = $this->paidPayment($order);

        $order->update(['total_minor' => 99900]); // edited before a queued delivery ran

        $this->assertFalse($payment->fulfilOnce(fn () => $this->fail('delivered an edited order')));
        $payment->refresh();
        $this->assertTrue($payment->hasFlag(Flag::OrderChanged));
        $this->assertNull($payment->fulfilled_at);
        $this->assertNull($payment->next_dispatch_at);
        Event::assertDispatched(PaymentOrderChanged::class);
    }

    public function test_fulfil_once_refuses_unpaid_blocked_or_refunded_payments(): void
    {
        $unpaid = $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
        $blocked = $this->paidPayment();
        $blocked->forceFill(['flags' => [Flag::AmountMismatch->value => 'now']])->save();
        $refunded = $this->paidPayment();
        $refunded->forceFill(['status' => PaymentStatus::Refunded])->save();

        foreach ([$unpaid, $blocked, $refunded] as $payment) {
            $this->assertRaises(fn () => $payment->fulfilOnce(fn () => $this->fail('must not run')), LogicException::class, 'no blocking flag');
        }
    }

    public function test_the_first_re_send_waits_for_queue_lag(): void
    {
        Event::fake([PaymentPaid::class]); // a queued listener that has not run yet
        $this->paidPayment();

        $this->travel(9)->minutes();
        $this->reconcile();
        Event::assertDispatchedTimes(PaymentPaid::class, 1);

        $this->travel(2)->minutes();
        $this->reconcile();
        Event::assertDispatchedTimes(PaymentPaid::class, 2);
    }

    public function test_the_first_re_send_wait_can_be_configured(): void
    {
        config()->set('fawaterk.reconcile.first_redispatch_minutes', 30);
        Event::fake([PaymentPaid::class]);
        $this->paidPayment();

        $this->travel(29)->minutes();
        $this->reconcile();
        Event::assertDispatchedTimes(PaymentPaid::class, 1);

        $this->travel(2)->minutes();
        $this->reconcile();
        Event::assertDispatchedTimes(PaymentPaid::class, 2);
    }

    public function test_the_time_budget_covers_re_sends(): void
    {
        config()->set('fawaterk.reconcile.max_seconds', 240);
        $sent = 0;
        Event::listen(PaymentPaid::class, function () use (&$sent) {
            $sent++;
            $this->travel(150)->seconds(); // a slow listener
            throw new RuntimeException('not delivered');
        });
        foreach (range(1, 3) as $i) {
            $this->paidPayment();
        }
        $this->travelTo(now()->addMinutes(30));
        $sent = 0;

        $this->reconcile();

        $this->assertSame(2, $sent, 'the run stopped once past its budget');
    }

    public function test_slow_re_reads_leave_time_for_re_sends(): void
    {
        config()->set('fawaterk.reconcile.max_seconds', 240);
        Event::fake([PaymentPaid::class]);
        $paid = $this->paidPayment();
        foreach (range(1, 3) as $i) {
            $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
        }
        $this->fake->getTransactionUsing(function (string $key) use ($paid) {
            if ($key !== $paid->intent_key) {
                $this->travel(100)->seconds(); // Fawaterk answering slowly
            }

            return null;
        });
        $this->travel(15)->minutes();

        $report = app(Reconciler::class)->run();

        $this->assertLessThan(3, $report->checked, 're-reads stop at their share of the budget');
        $this->assertSame(1, $report->redispatched);
        Event::assertDispatchedTimes(PaymentPaid::class, 2);
    }

    public function test_overlapping_runs_send_paid_once(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->paidPayment();
        $this->travel(15)->minutes();
        $selectedByBothRuns = $payment->fresh();

        app(PaymentRecorder::class)->dispatchPaid($payment->fresh(), onlyIfDue: true);
        app(PaymentRecorder::class)->dispatchPaid($selectedByBothRuns, onlyIfDue: true);

        Event::assertDispatchedTimes(PaymentPaid::class, 2); // the first send and one re-send
    }

    private function reconcile(): void
    {
        app(Reconciler::class)->run();
    }

    private function paidPayment(?Order $order = null): FawaterkPayment
    {
        $payment = $this->payment(Fawaterk::checkout($order ?? $this->order())->paymentUuid);
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key));

        return $payment->refresh();
    }

    private function send(SignedWebhook $webhook): void
    {
        $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson())->assertOk();
    }
}

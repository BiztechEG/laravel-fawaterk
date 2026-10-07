<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentOrderChanged;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentPaidTwice;
use BiztechEG\Fawaterk\Events\PaymentPending;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Tests\Fixtures\Order;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use RuntimeException;

class LedgerTest extends LedgerTestCase
{
    public function test_a_paid_re_read_pays_the_row_once(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();

        $this->apply($payment, paid: true, transactionId: 555);
        $this->apply($payment, paid: true, transactionId: 555);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(15000, $payment->paid_amount_minor);
        $this->assertSame(555, $payment->fawaterk_transaction_id);
        $this->assertNotNull($payment->paid_at);
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
        Event::assertDispatched(PaymentPaid::class, fn (PaymentPaid $event) => ! $event->late && $event->payment->is($payment));
    }

    public function test_paid_never_goes_back_and_pending_is_reported_once(): void
    {
        Event::fake([PaymentPending::class]);
        $payment = $this->started();

        $this->apply($payment, paid: false, transactionId: 555);
        $this->apply($payment, paid: false, transactionId: 555);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        Event::assertDispatchedTimes(PaymentPending::class, 1);

        $this->apply($payment, paid: true);
        $this->apply($payment, paid: false);
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function test_a_payment_after_failure_or_expiry_is_late_but_still_paid(): void
    {
        Event::fake([PaymentPaid::class]);

        foreach ([PaymentStatus::Failed, PaymentStatus::Expired] as $status) {
            $payment = $this->started();
            $payment->forceFill(['status' => $status])->save();

            $this->apply($payment, paid: true);

            $payment->refresh();
            $this->assertSame(PaymentStatus::Paid, $payment->status);
            $this->assertTrue($payment->hasFlag(Flag::LatePayment));
            $this->assertFalse($payment->hasBlockingFlag());
        }

        Event::assertDispatchedTimes(PaymentPaid::class, 2);
        Event::assertDispatched(PaymentPaid::class, fn (PaymentPaid $event) => $event->late);
    }

    public function test_a_wrong_amount_or_currency_is_flagged_instead_of_paid(): void
    {
        Event::fake([PaymentPaid::class, PaymentAmountMismatch::class]);

        $short = $this->started();
        $this->apply($short, paid: true, totalMinor: 14999);

        $dollars = $this->started();
        $this->apply($dollars, paid: true, currency: 'USD');

        foreach ([$short, $dollars] as $payment) {
            $payment->refresh();
            $this->assertSame(PaymentStatus::Paid, $payment->status, 'the money arrived');
            $this->assertTrue($payment->hasFlag(Flag::AmountMismatch));
        }

        Event::assertNotDispatched(PaymentPaid::class);
        Event::assertDispatched(PaymentAmountMismatch::class, fn ($event) => $event->expectedMinor === 15000 && $event->paidMinor === 14999);
        Event::assertDispatchedTimes(PaymentAmountMismatch::class, 2);
    }

    public function test_a_second_payment_for_the_same_purpose_is_flagged_paid_twice(): void
    {
        Event::fake([PaymentPaid::class, PaymentPaidTwice::class]);
        $order = $this->order();
        $first = $this->started($order, 'fawry');
        $second = $this->started($order, 'hosted');

        $this->apply($first, paid: true);
        $this->apply($second, paid: true);

        $this->assertTrue($second->refresh()->hasFlag(Flag::PaidTwice));
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
        Event::assertDispatchedTimes(PaymentPaidTwice::class, 1);
    }

    public function test_different_purposes_are_paid_independently(): void
    {
        Event::fake([PaymentPaid::class, PaymentPaidTwice::class]);
        $order = $this->order();

        $this->apply($this->started($order, 'hosted', 'deposit'), paid: true, totalMinor: 7500);
        $this->apply($this->started($order, 'hosted', 'balance'), paid: true);

        Event::assertDispatchedTimes(PaymentPaid::class, 2);
        Event::assertNotDispatched(PaymentPaidTwice::class);
    }

    public function test_a_changed_or_deleted_payable_is_flagged(): void
    {
        Event::fake([PaymentPaid::class, PaymentOrderChanged::class]);

        $changed = $this->order();
        $payment = $this->started($changed);
        $changed->update(['total_minor' => 15000, 'user_id' => 8]);
        $this->apply($payment, paid: true);
        $this->assertTrue($payment->refresh()->hasFlag(Flag::OrderChanged));

        $deleted = $this->order();
        $gone = $this->started($deleted);
        $deleted->delete();
        $this->apply($gone, paid: true);
        $this->assertTrue($gone->refresh()->hasFlag(Flag::PayableMissing));

        Event::assertNotDispatched(PaymentPaid::class);
        Event::assertDispatched(PaymentOrderChanged::class, fn ($event) => ! $event->payableMissing);
        Event::assertDispatched(PaymentOrderChanged::class, fn ($event) => $event->payableMissing);
    }

    public function test_commission_modes(): void
    {
        Event::fake([PaymentPaid::class, PaymentAmountMismatch::class]);

        $cases = [
            // mode, profile, re-read total, re-read method name, expect paid?
            ['merchant', 'hosted', 15000, 'Fawry', true],
            ['merchant', 'hosted', 15250, 'Fawry', false],
            ['customer', 'hosted', 15250, 'Visa-Mastercard', true],
            ['auto', 'fawry', 15250, null, true],             // stored id 3: commission on the customer
            ['auto', 'card', 15000, null, true],              // stored id 2: the merchant absorbs it
            ['auto', 'hosted', 15250, 'فوري', true],         // hosted: the localised name resolves Fawry
            ['auto', 'hosted', 15250, 'Unknown wallet', false], // unknown method: mismatch
        ];

        foreach ($cases as $i => [$mode, $profile, $total, $method, $paid]) {
            config()->set('fawaterk.commission', $mode);
            $payment = $this->started(null, $profile);
            $this->apply($payment, paid: true, totalMinor: $total, commissionMinor: 250, paymentMethod: $method);

            $this->assertSame(! $paid, $payment->refresh()->hasFlag(Flag::AmountMismatch), "case {$i}: {$mode} {$profile} {$total}");
        }
    }

    public function test_manual_fulfilment_is_idempotent_and_only_for_paid_rows(): void
    {
        $payment = $this->started();

        $this->assertRaises(fn () => $payment->markFulfilled(), LogicException::class);

        $this->apply($payment, paid: true);
        $payment->refresh()->markFulfilled();
        $first = $payment->fulfilled_at;
        $this->travel(5)->minutes();
        $payment->markFulfilled();

        $this->assertEquals($first, $payment->refresh()->fulfilled_at);
        $this->assertSame(1, $payment->fulfil_attempts);
    }

    public function test_manual_fulfilment_refuses_a_row_with_a_blocking_flag(): void
    {
        Event::fake([PaymentPaid::class, PaymentAmountMismatch::class]);
        $payment = $this->started();
        $this->apply($payment, paid: true, totalMinor: 14999);

        $this->assertTrue($payment->refresh()->hasFlag(Flag::AmountMismatch));
        $this->assertRaises(fn () => $payment->markFulfilled(), LogicException::class);
        $this->assertNull($payment->refresh()->fulfilled_at, 'a flagged row stays in the unfulfilled lists');
    }

    public function test_after_listeners_fulfilment_and_failing_listeners(): void
    {
        config()->set('fawaterk.fulfilment', 'after_listeners');

        $ok = $this->started();
        $this->apply($ok, paid: true);
        $this->assertNotNull($ok->refresh()->fulfilled_at);

        Event::listen(PaymentPaid::class, fn () => throw new RuntimeException('SMTP down'));
        $failing = $this->started();
        $this->apply($failing, paid: true);

        $failing->refresh();
        $this->assertSame(PaymentStatus::Paid, $failing->status, 'a failing listener never undoes the ledger');
        $this->assertNull($failing->fulfilled_at);
        $this->assertSame(1, $failing->fulfil_attempts);
    }

    public function test_events_wait_for_the_outer_transaction_to_commit(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();

        DB::transaction(function () use ($payment) {
            $this->apply($payment, paid: true);
            Event::assertNotDispatched(PaymentPaid::class);
        });

        Event::assertDispatched(PaymentPaid::class);

        $rolledBack = $this->started();
        try {
            DB::transaction(function () use ($rolledBack) {
                $this->apply($rolledBack, paid: true);
                throw new RuntimeException('app failure');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(PaymentStatus::Created, $rolledBack->refresh()->status);
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    public function test_events_survive_queue_serialisation(): void
    {
        $payment = $this->started();
        $event = unserialize(serialize(new PaymentPaid($payment, true)));

        $this->assertInstanceOf(PaymentPaid::class, $event);
        $this->assertTrue($event->payment->is($payment));
        $this->assertTrue($event->late);
    }

    public function test_morph_maps_are_respected(): void
    {
        Event::fake([PaymentPaid::class]);
        Relation::morphMap(['order' => Order::class]);

        try {
            $payment = $this->started();
            $this->assertSame('order', $payment->payable_type);

            $this->apply($payment, paid: true);

            $this->assertFalse($payment->refresh()->hasBlockingFlag());
            Event::assertDispatched(PaymentPaid::class);
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_a_custom_payment_model_is_used(): void
    {
        $model = new class extends FawaterkPayment {};
        Fawaterk::usePaymentModel($model::class);

        $payment = $this->started();

        $this->assertInstanceOf($model::class, $payment);
        $this->assertInstanceOf($model::class, $this->order()->fawaterkPayments()->make());
    }

    private function started(?Order $order = null, string $profile = 'hosted', string $purpose = 'default'): FawaterkPayment
    {
        $result = Fawaterk::checkout($order ?? $this->order(), new CheckoutContext($profile, $purpose));

        return $this->payment($result->paymentUuid);
    }

    private function apply(
        FawaterkPayment $payment,
        bool $paid,
        ?int $totalMinor = null,
        int $transactionId = 1001,
        string $currency = 'EGP',
        ?int $commissionMinor = null,
        ?string $paymentMethod = 'Visa-Mastercard',
    ): void {
        app(PaymentRecorder::class)->applyReRead($payment, new TransactionData(
            intentKey: (string) $payment->intent_key,
            transactionId: $transactionId,
            paid: $paid,
            totalMinor: $totalMinor ?? $payment->amount_minor,
            currency: $currency,
            commissionMinor: $commissionMinor,
            paymentMethod: $paymentMethod,
            statusText: $paid ? 'paid' : 'unpaid',
            paidAt: $paid ? '2026-09-29 12:00:00' : null,
        ));
    }
}

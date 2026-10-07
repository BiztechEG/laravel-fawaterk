<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Events\PaymentRefunded;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Refund webhooks are watched until refund/index confirms them: amounts
 * come only from the list, and an unconfirmed refund is reported once, when
 * the watch window ends.
 */
class RefundWatchTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The watch on its own: the daily read of the whole list (RefundSafetyNetTest) would count a refund
        // the watch missed and hide it.
        config()->set('fawaterk.reconcile.refund_list_scan_hours', 0);
        Event::fake([PaymentRefunded::class, PaymentRefundReported::class]);
    }

    public function test_a_refund_listed_later_is_counted_by_the_watch(): void
    {
        $payment = $this->paidPayment(555);

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        $payment->refresh();
        $this->assertSame(0, $payment->refunded_amount_minor, 'not in the list yet');
        $this->assertSame(['5000|EGP'], $this->pendingKeys($payment));
        $this->assertNotNull($payment->next_refund_check_at);
        Event::assertNothingDispatched();

        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(10, '3', 555, 5000, 'approved')]));
        $this->travel(5)->minutes();
        $this->reconcile();

        $payment->refresh();
        $this->assertSame(5000, $payment->refunded_amount_minor);
        $this->assertNull($payment->refund_pending);
        $this->assertFalse($payment->hasFlag(Flag::RefundUnverified));

        // The list is still scanned until the window ends (another refund may follow), then no more.
        $this->runReconcile(hours: 6);
        $this->assertNull($payment->refresh()->next_refund_check_at);
        $this->assertNull($payment->refund_watch_until);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Event::assertNotDispatched(PaymentRefundReported::class);
    }

    public function test_an_unconfirmed_refund_is_reported_once_when_the_window_ends(): void
    {
        $payment = $this->paidPayment(555);
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $scans = $this->fake->refundPageCalls();

        foreach (range(1, 71) as $step) { // 5 h 55 min
            $this->travel(5)->minutes();
            $this->reconcile();
        }

        Event::assertNotDispatched(PaymentRefundReported::class);
        $this->assertFalse($payment->refresh()->hasFlag(Flag::RefundUnverified), 'not before the window ends');
        $this->assertLessThan(12, $this->fake->refundPageCalls() - $scans, 'the checks back off');

        foreach (range(1, 4) as $step) {
            $this->travel(5)->minutes();
            $this->reconcile();
        }

        $payment->refresh();
        $this->assertTrue($payment->hasFlag(Flag::RefundUnverified));
        $this->assertSame(0, $payment->refunded_amount_minor);
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNull($payment->refund_pending);
        $this->assertNull($payment->next_refund_check_at);
        Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
        Event::assertDispatched(PaymentRefundReported::class, fn ($event) => $event->payment?->is($payment) && $event->transactionId === 555 && $event->amount === '50.00' && $event->currency === 'EGP');
    }

    public function test_the_list_being_down_never_loses_the_refund(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->failRefundPages(new ServiceUnavailableException('down', 503));

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        Event::assertNothingDispatched();

        $this->fake->failRefundPages(null);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(10, '3', 555, 5000, 'approved')]));
        $this->travel(5)->minutes();
        $this->reconcile();

        $this->assertSame(5000, $payment->refresh()->refunded_amount_minor);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Event::assertNotDispatched(PaymentRefundReported::class);
    }

    public function test_a_list_that_stays_down_is_reported_a_day_after_the_window(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->failRefundPages(new ServiceUnavailableException('down', 503));
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        $this->travel(6)->hours();
        $this->travel(1)->minutes();
        $this->reconcile();
        Event::assertNotDispatched(PaymentRefundReported::class);
        $this->assertNotNull($payment->refresh()->next_refund_check_at, 'still retried');

        $this->travel(1)->days();
        $this->reconcile();

        $this->assertTrue($payment->refresh()->hasFlag(Flag::RefundUnverified));
        $this->assertNull($payment->next_refund_check_at);
        Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
    }

    public function test_refund_keys_use_minor_units(): void
    {
        $this->paidPayment(555);

        $this->send(SignedWebhook::refund(555, '50'))->assertOk();
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        $this->assertLogged('accepted', '555|5000|EGP');
        $this->assertLogged('duplicate');
    }

    public function test_a_transaction_that_is_not_ours_is_reported_once(): void
    {
        $this->send(SignedWebhook::refund(999999, '20.00'))->assertOk();
        $this->travel(2)->minutes();
        $this->send(SignedWebhook::refund(999999, '20.00'))->assertOk();

        Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
        Event::assertDispatched(PaymentRefundReported::class, fn ($event) => $event->payment === null && $event->transactionId === 999999);
    }

    public function test_an_unreadable_amount_is_flagged_and_reported_once(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(10, '3', 555, 5000, 'approved')]));

        $this->send(SignedWebhook::refund(555, '50.001'))->assertOk();
        $this->travel(2)->minutes();
        $this->send(SignedWebhook::refund(555, '50.001'))->assertOk();

        $payment->refresh();
        $this->assertTrue($payment->hasFlag(Flag::RefundUnverified));
        $this->assertNull($payment->refund_pending, 'nothing to watch');
        Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
    }

    public function test_with_verification_off_each_refund_is_reported_once(): void
    {
        config()->set('fawaterk.reread.refund', false);
        $payment = $this->paidPayment(555);

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $this->travel(2)->minutes();
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $this->send(SignedWebhook::refund(555, '20.00'))->assertOk();

        $this->assertTrue($payment->refresh()->hasFlag(Flag::RefundUnverified));
        $this->assertSame(0, $payment->refunded_amount_minor);
        Event::assertDispatchedTimes(PaymentRefundReported::class, 2);
    }

    public function test_a_refund_for_a_row_we_think_unpaid_re_checks_it(): void
    {
        $payment = $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
        $payment->forceFill(['fawaterk_transaction_id' => 555, 'next_check_at' => now()->addHour()])->save();

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        $payment->refresh();
        $this->assertTrue($payment->next_check_at->lte(now()), 'a refund means it was paid');
        $this->assertSame(['5000|EGP'], $this->pendingKeys($payment));
    }

    // Round 3 of the review.

    public function test_a_second_refund_of_the_same_amount_is_counted_when_it_is_listed(): void
    {
        $payment = $this->paidPayment(555);
        $first = new RefundItem(10, '3', 555, 5000, 'approved');
        $this->fake->setRefundPage(new RefundPage(1, 1, [$first]));
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $this->assertSame(5000, $payment->refresh()->refunded_amount_minor);

        // The second refund of 50: its webhook comes first, the list catches up later.
        $this->travel(2)->minutes();
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $this->assertSame(['5000|EGP'], $this->pendingKeys($payment->refresh()), 'the counted refund of 50 does not confirm this one');

        $this->fake->setRefundPage(new RefundPage(1, 1, [$first, new RefundItem(11, '3', 555, 5000, 'approved')]));
        $this->runReconcile(hours: 7);

        $payment->refresh();
        $this->assertSame(10000, $payment->refunded_amount_minor);
        $this->assertNull($payment->refund_pending);
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
        Event::assertNotDispatched(PaymentRefundReported::class);
    }

    public function test_equal_refunds_whose_second_webhook_was_dropped_are_both_counted(): void
    {
        $payment = $this->paidPayment(555);
        $first = new RefundItem(10, '3', 555, 7500, 'approved');
        $this->fake->setRefundPage(new RefundPage(1, 1, [$first]));
        $this->send(SignedWebhook::refund(555, '75.00'))->assertOk();
        $this->send(SignedWebhook::refund(555, '75.00'))->assertOk(); // the second refund, inside the cooldown

        // The list keeps being scanned for the whole window, not only while something is pending.
        $this->travel(30)->minutes();
        $this->fake->setRefundPage(new RefundPage(1, 1, [$first, new RefundItem(11, '3', 555, 7500, 'approved')]));
        $this->runReconcile(hours: 7);

        $payment->refresh();
        $this->assertSame(15000, $payment->refunded_amount_minor);
        $this->assertSame(PaymentStatus::Refunded, $payment->status, 'refunded in full: never delivered again');
    }

    public function test_replays_neither_extend_the_window_nor_stack_alerts(): void
    {
        $payment = $this->paidPayment(555);
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $until = $payment->refresh()->refund_watch_until;

        $this->travel(5)->hours();
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk(); // a replay while it is watched
        $this->assertEquals($until, $payment->refresh()->refund_watch_until, 'the window is not extended');

        $this->runReconcile(hours: 1, minutes: 10);
        Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
        $this->assertNull($payment->refresh()->refund_pending);
    }

    public function test_a_replay_of_a_counted_refund_is_reported_once_per_window_and_never_counted(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(10, '3', 555, 5000, 'approved')]));
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        // Weeks later a captured copy is replayed: it cannot be told from a new refund of 50
        // that is not listed yet, so it is reported (a signal worth an admin's look), once.
        $this->travel(20)->days();
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $this->runReconcile(hours: 7);

        $payment->refresh();
        $this->assertSame(5000, $payment->refunded_amount_minor);
        $this->assertTrue($payment->hasFlag(Flag::RefundUnverified));
        Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    public function test_the_refund_cooldown_starts_only_once_the_watch_is_saved(): void
    {
        $payment = $this->paidPayment(555);
        $failOnce = true;
        FawaterkPayment::saving(function (FawaterkPayment $row) use (&$failOnce) {
            if ($failOnce && $row->isDirty('refund_pending')) {
                $failOnce = false;

                throw new RuntimeException('database went away');
            }
        });

        $this->send(SignedWebhook::refund(555, '50.00'))->assertStatus(500);
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk(); // Fawaterk's retry, within the cooldown

        $this->assertSame(['5000|EGP'], $this->pendingKeys($payment->refresh()));
    }

    public function test_an_unconfirmed_refund_alert_survives_a_crash(): void
    {
        $payment = $this->paidPayment(555);
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();
        $this->runReconcile(hours: 6, minutes: 10);

        $this->assertNull($payment->refresh()->next_alert_at, 'cleared once sent');
        $this->assertNull($payment->refund_pending);

        // The same state, as a crash after the commit leaves it.
        $payment->forceFill([
            'refund_pending' => [['key' => '2000|EGP', 'until' => now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z'), 'known' => [], 'report' => true]],
            'next_alert_at' => now()->subMinute(),
        ])->save();
        $this->reconcile();
        $this->reconcile();

        Event::assertDispatched(PaymentRefundReported::class, fn ($event) => $event->amount === '20.00');
        Event::assertDispatchedTimes(PaymentRefundReported::class, 2);
        $this->assertNull($payment->refresh()->refund_pending);
    }

    /**
     * @return list<string>
     */
    private function pendingKeys(FawaterkPayment $payment): array
    {
        return array_column($payment->refund_pending ?? [], 'key');
    }

    private function runReconcile(int $hours, int $minutes = 0): void
    {
        foreach (range(1, intdiv($hours * 60 + $minutes, 5)) as $step) {
            $this->travel(5)->minutes();
            $this->reconcile();
        }
    }

    private function reconcile(): void
    {
        app(Reconciler::class)->run();
    }

    private function paidPayment(int $transactionId): FawaterkPayment
    {
        $payment = $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
        $this->fake->markPaid((string) $payment->intent_key, transactionId: $transactionId);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, $transactionId))->assertOk();

        return $payment->refresh();
    }

    private function send(SignedWebhook $webhook): TestResponse
    {
        return $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
    }

    private function assertLogged(string $outcome, ?string $dedupeKey = null): void
    {
        $query = WebhookEvent::query()->where('type', 'refund')->where('outcome', $outcome);

        if ($dedupeKey !== null) {
            $query->where('dedupe_key', $dedupeKey);
        }

        $this->assertTrue($query->exists(), "No refund webhook logged as {$outcome}".($dedupeKey === null ? '' : " with {$dedupeKey}").'.');
    }
}

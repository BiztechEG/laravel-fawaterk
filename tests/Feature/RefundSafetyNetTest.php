<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Events\PaymentRefunded;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\RefundWebhookMisrouted;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Refunds\RefundVerifier;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * A refund must not go unnoticed when its webhook is lost, for example when the dashboard's Refund field holds
 * the Failed URL: the refund webhook is refused there and nothing knows of the refund.
 *
 * - a correctly signed refund body at another webhook URL is not applied, but raised once (RefundWebhookMisrouted)
 * - reconcile reads the refund list once a day and counts approved refunds of our payments that no webhook announced
 */
class RefundSafetyNetTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PaymentRefunded::class, PaymentRefundReported::class, RefundWebhookMisrouted::class]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherUrls(): array
    {
        return ['failed' => ['failed_json'], 'paid' => ['paid_json'], 'cancel' => ['cancel_json'], 'form body' => ['failed']];
    }

    /**
     * @dataProvider otherUrls
     */
    #[DataProvider('otherUrls')]
    public function test_a_signed_refund_at_another_url_is_raised_once_and_not_applied(string $segment): void
    {
        $payment = $this->paidPayment(555);
        $webhook = SignedWebhook::refund(555, '40');

        $this->sendTo($segment, $webhook)->assertOk();
        $this->sendTo($segment, $webhook)->assertOk();

        $payment->refresh();
        $this->assertSame(0, $payment->refunded_amount_minor);
        $this->assertNull($payment->refund_pending);
        $this->assertNull($payment->next_refund_check_at);
        Event::assertNotDispatched(PaymentRefunded::class);
        Event::assertNotDispatched(PaymentRefundReported::class);

        $type = str_replace('_json', '', $segment);
        Event::assertDispatchedTimes(RefundWebhookMisrouted::class, 1);
        Event::assertDispatched(RefundWebhookMisrouted::class, fn (RefundWebhookMisrouted $event) => $event->receivedAt === $type
            && $event->transactionId === 555 && $event->amount === '40' && $event->currency === 'EGP');
        $this->assertSame(2, WebhookEvent::query()->where('type', $type)->where('outcome', 'misrouted')->count());
    }

    public function test_a_refund_body_with_a_bad_signature_at_another_url_is_refused(): void
    {
        $this->paidPayment(555);

        $this->sendTo('failed_json', SignedWebhook::refund(555, '40')->signedWith('not-the-vendor-key'))->assertStatus(401);

        Event::assertNotDispatched(RefundWebhookMisrouted::class);
        $this->assertFalse(WebhookEvent::query()->where('outcome', 'misrouted')->exists());
    }

    public function test_a_real_failed_webhook_is_not_taken_for_a_refund(): void
    {
        $payment = $this->paidPayment(555);

        $this->sendTo('failed_json', SignedWebhook::failed((string) $payment->intent_key, 555))->assertOk();

        Event::assertNotDispatched(RefundWebhookMisrouted::class);
    }

    public function test_the_daily_scan_counts_a_refund_no_webhook_announced(): void
    {
        $payment = $this->paidPayment(555);
        $other = $this->paidPayment(556);
        $this->fake->setRefundPage(new RefundPage(1, 1, [
            new RefundItem(30, 'Transaction', 555, 2500, 'approved'),
            new RefundItem(31, 'Transaction', 999, 1000, 'approved'),  // not ours
            new RefundItem(32, 'invoice', 556, 1000, 'approved'),      // an invoice refund
            new RefundItem(33, 'Transaction', 556, 1000, 'pending'),   // not approved
        ]));

        $this->reconcile();

        $this->assertSame(2500, $payment->refresh()->refunded_amount_minor);
        $this->assertSame(['30' => 2500], $payment->refund_ids);
        $this->assertSame(0, $other->refresh()->refunded_amount_minor);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);

        // Once a day: the next runs do not read the list again, and nothing is counted twice.
        $calls = $this->fake->refundPageCalls();
        $this->travel(23)->hours();
        $this->reconcile();
        $this->assertSame($calls, $this->fake->refundPageCalls());

        $this->travel(61)->minutes();
        $this->reconcile();
        $this->assertGreaterThan($calls, $this->fake->refundPageCalls());
        $this->assertSame(2500, $payment->refresh()->refunded_amount_minor);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    public function test_only_transaction_refunds_count_on_our_payment(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [
            // Other refundable kinds may share the number of our transaction id.
            new RefundItem(40, '1', 555, 100, 'approved'),            // a payment link
            new RefundItem(41, '2', 555, 100, 'approved'),            // a collection link
            new RefundItem(42, '', 555, 100, 'approved'),
            new RefundItem(43, 'payment_link', 555, 100, 'approved'),
            new RefundItem(44, 'Invoice', 555, 100, 'approved'),
            // What the API gives for a transaction refund, and the documented type number.
            new RefundItem(45, 'Transaction', 555, 1000, 'approved'),
            new RefundItem(46, '3', 555, 500, 'approved'),
        ]));

        $this->reconcile();

        $this->assertSame(['45' => 1000, '46' => 500], $payment->refresh()->refund_ids);
        $this->assertSame(1500, $payment->refunded_amount_minor);
    }

    public function test_a_webhook_after_the_scan_counted_its_refund_is_confirmed_at_once(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(601, 'Transaction', 555, 4000, 'approved')]));
        $this->reconcile();
        $this->assertSame(['601'], $payment->refresh()->refund_unannounced);

        // Late, or resent from the dashboard once its Refund field was fixed.
        $this->sendTo('refund_json', SignedWebhook::refund(555, '40'))->assertOk();

        $payment->refresh();
        $this->assertNull($payment->refund_pending);
        $this->assertNull($payment->refund_unannounced);
        $this->assertSame(4000, $payment->refunded_amount_minor);

        $this->travel(7)->hours();
        $this->reconcile();
        $this->assertFalse($payment->refresh()->hasFlag(Flag::RefundUnverified));
        Event::assertNotDispatched(PaymentRefundReported::class);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    public function test_a_refund_the_scan_counts_while_its_webhook_waits_is_not_unannounced(): void
    {
        $payment = $this->paidPayment(555);
        $this->sendTo('refund_json', SignedWebhook::refund(555, '40'))->assertOk(); // not in the list yet
        $this->assertSame(['4000|EGP'], array_column($payment->refresh()->refund_pending, 'key'));

        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(601, 'Transaction', 555, 4000, 'approved')]));
        app(RefundVerifier::class)->scanList(fn (int $id) => $id === 555 ? $payment->refresh() : null);

        $this->assertNull($payment->refresh()->refund_unannounced, 'the waiting webhook claims it');
        $this->travel(5)->minutes();
        $this->reconcile();
        $this->assertNull($payment->refresh()->refund_pending);
        $this->travel(7)->hours();
        $this->reconcile();
        Event::assertNotDispatched(PaymentRefundReported::class);
    }

    public function test_each_webhook_uses_one_unannounced_refund_of_its_amount(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [
            new RefundItem(601, 'Transaction', 555, 1000, 'approved'),
            new RefundItem(602, 'Transaction', 555, 1000, 'approved'),
            new RefundItem(603, 'Transaction', 555, 2500, 'approved'),
        ]));
        $this->reconcile();

        $this->sendTo('refund_json', SignedWebhook::refund(555, '10'))->assertOk();

        $payment->refresh();
        $this->assertNull($payment->refund_pending);
        $this->assertCount(2, $payment->refund_unannounced);
        $this->assertContains('603', $payment->refund_unannounced);

        // A replay of it now has nothing left to use but the other 10: still confirmed, as two refunds of 10 exist.
        $this->travel(2)->minutes();
        $this->sendTo('refund_json', SignedWebhook::refund(555, '10'))->assertOk();
        $this->assertSame(['603'], $payment->refresh()->refund_unannounced);

        // A third one of 10 is a replay: it waits, and is reported when its window ends.
        $this->travel(2)->minutes();
        $this->sendTo('refund_json', SignedWebhook::refund(555, '10'))->assertOk();
        $this->assertSame(['1000|EGP'], array_column($payment->refresh()->refund_pending, 'key'));
    }

    public function test_the_scan_reads_at_most_the_configured_pages(): void
    {
        config()->set('fawaterk.reconcile.refund_scan_pages', 2);
        $payment = $this->paidPayment(555);
        foreach ([1, 2, 3] as $number) {
            $this->fake->setRefundPage(new RefundPage($number, 3, [new RefundItem(40 + $number, 'Transaction', 555, 100, 'approved')]));
        }

        $this->reconcile();

        $this->assertSame(2, $this->fake->refundPageCalls());
        $this->assertSame(['41' => 100, '42' => 100], $payment->refresh()->refund_ids);
    }

    public function test_a_failed_scan_is_tried_again_after_half_an_hour(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->failRefundPages(new ServiceUnavailableException('down', 503));

        $this->reconcile();
        $calls = $this->fake->refundPageCalls();
        $this->assertGreaterThan(0, $calls);

        $this->fake->failRefundPages(null);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(30, 'Transaction', 555, 2500, 'approved')]));
        $this->travel(29)->minutes();
        $this->reconcile();
        $this->assertSame($calls, $this->fake->refundPageCalls());

        $this->travel(2)->minutes();
        $this->reconcile();
        $this->assertSame(2500, $payment->refresh()->refunded_amount_minor);
    }

    public function test_any_failed_scan_is_tried_again_after_half_an_hour(): void
    {
        $payment = $this->paidPayment(555);
        $this->fake->failRefundPages(new RuntimeException('a deadlock'));

        $this->reconcile();

        $this->fake->failRefundPages(null);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(30, 'Transaction', 555, 2500, 'approved')]));
        $this->travel(31)->minutes();
        $this->reconcile();
        $this->assertSame(2500, $payment->refresh()->refunded_amount_minor);
    }

    public function test_the_scan_stops_between_pages_when_the_run_must_stop(): void
    {
        foreach ([1, 2, 3] as $number) {
            $this->fake->setRefundPage(new RefundPage($number, 3, []));
        }

        app(RefundVerifier::class)->scanList(fn () => null, fn () => true);

        $this->assertSame(1, $this->fake->refundPageCalls());
    }

    public function test_the_scan_is_off_when_refund_rereads_are_off(): void
    {
        config()->set('fawaterk.reread.refund', false);
        $this->paidPayment(555);

        $this->reconcile();

        $this->assertSame(0, $this->fake->refundPageCalls());
    }

    public function test_a_real_cancel_webhook_is_not_taken_for_a_refund(): void
    {
        $this->paidPayment(555);

        $this->sendTo('cancel_json', SignedWebhook::cancel(7001))->assertOk();

        Event::assertNotDispatched(RefundWebhookMisrouted::class);
    }

    public function test_the_scan_can_be_turned_off(): void
    {
        config()->set('fawaterk.reconcile.refund_list_scan_hours', 0);
        $this->paidPayment(555);

        $this->reconcile();

        $this->assertSame(0, $this->fake->refundPageCalls());
    }

    public function test_no_paid_payment_no_scan(): void
    {
        $this->reconcile();

        $this->assertSame(0, $this->fake->refundPageCalls());
    }

    private function reconcile(): void
    {
        app(Reconciler::class)->run();
    }

    private function paidPayment(int $transactionId): FawaterkPayment
    {
        $payment = $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
        $this->fake->markPaid((string) $payment->intent_key, transactionId: $transactionId);
        $this->call('POST', '/fawaterk/webhooks/paid_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], SignedWebhook::paid((string) $payment->intent_key, $transactionId)->toJson())->assertOk();

        return $payment->refresh();
    }

    private function sendTo(string $segment, SignedWebhook $webhook): TestResponse
    {
        if (str_ends_with($segment, '_json')) {
            return $this->call('POST', '/fawaterk/webhooks/'.$segment, [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
        }

        return $this->call('POST', '/fawaterk/webhooks/'.$segment, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($webhook->toArray()));
    }
}

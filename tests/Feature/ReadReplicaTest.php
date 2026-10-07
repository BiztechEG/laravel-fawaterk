<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\UnknownPaymentPaid;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Apps with a read replica: every read that decides something goes to the
 * primary. Here the replica is an empty database, so a decision read sent to
 * it fails loudly ("no such table") instead of silently acting on stale data.
 */
class ReadReplicaTest extends LedgerTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'prefix' => '',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
            'write' => ['database' => ':memory:'],
            'sticky' => false,
        ]);
    }

    public function test_decisions_never_read_from_a_lagging_replica(): void
    {
        Event::fake([PaymentPaid::class, UnknownPaymentPaid::class, PaymentRefundReported::class]);
        $order = $this->order();

        $first = Fawaterk::checkout($order);
        $this->assertTrue(Fawaterk::checkout($order)->reused, 'the checkout saw its own row');

        $payment = FawaterkPayment::query()->useWritePdo()->where('uuid', $first->paymentUuid)->firstOrFail();
        $this->fake->markPaid((string) $payment->intent_key, transactionId: 555);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 555))->assertOk();
        $this->send(SignedWebhook::failed((string) $payment->intent_key, 555))->assertOk();

        $this->fake->getTransactionUsing(fn (string $key) => $key === (string) $payment->intent_key ? null : new TransactionData($key, 99, true, 100, 'EGP', null, 'Fawry', 'paid', null));
        foreach (range(1, 2) as $replay) {
            $this->send(SignedWebhook::paid('7ba7b810-9dad-11d1-80b4-00c04fd430c8', 99))->assertOk();
            $this->send(SignedWebhook::refund(999999, '20.00'))->assertOk();
            $this->send(SignedWebhook::refund(555, '20.00'))->assertOk();
            $this->travel(2)->minutes();
        }

        $this->travel(15)->minutes();
        app(Reconciler::class)->run();
        $payment->markFulfilled();

        $payment = FawaterkPayment::query()->useWritePdo()->whereKey($payment->getKey())->firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->fulfilled_at);
        Event::assertDispatchedTimes(PaymentPaid::class, 2);
        Event::assertDispatchedTimes(UnknownPaymentPaid::class, 1);
        Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
    }

    private function send(SignedWebhook $webhook): TestResponse
    {
        return $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
    }
}

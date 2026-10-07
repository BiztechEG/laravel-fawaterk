<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Illuminate\Support\Facades\Event;

/**
 * The ledger on its own connection, the app's orders on a connection with a
 * replica that lags forever (an empty database): the order is always read
 * from the primary, so its fingerprint is compared with what it really is.
 */
class PayableReplicaTest extends LedgerTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.ledger', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('fawaterk.ledger.connection', 'ledger');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'prefix' => '',
            'database' => ':memory:',
            'read' => ['database' => ':memory:'],
            'write' => ['database' => ':memory:'],
            'sticky' => false,
        ]);
    }

    public function test_the_payable_is_read_from_the_primary(): void
    {
        Event::fake([PaymentPaid::class]);
        $result = Fawaterk::checkout($this->order());
        $payment = FawaterkPayment::query()->where('uuid', $result->paymentUuid)->firstOrFail();
        $this->fake->markPaid((string) $payment->intent_key);

        $this->call('POST', '/fawaterk/webhooks/paid_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], SignedWebhook::paid((string) $payment->intent_key)->toJson())
            ->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertFalse($payment->hasBlockingFlag());
        Event::assertDispatched(PaymentPaid::class);
    }
}

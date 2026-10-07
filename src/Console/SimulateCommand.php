<?php

namespace BiztechEG\Fawaterk\Console;

use BiztechEG\Fawaterk\Data\Money;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Environment;
use BiztechEG\Fawaterk\Fawaterk;
use BiztechEG\Fawaterk\Http\Controllers\WebhookController;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Runs a signed webhook through the real pipeline, in this process only, with
 * Fawaterk replaced by the in-memory fake: your listeners run as they would
 * for a real payment. Refused on the live environment and in production.
 */
final class SimulateCommand extends Command
{
    protected $signature = 'fawaterk:simulate
        {type : paid, failed, cancel or refund}
        {payment : The payment uuid}
        {--amount= : Refund amount, for example 50.00 (default: the paid amount)}
        {--force : Do not ask before changing the payment}';

    protected $description = 'Simulate a Fawaterk webhook for a payment (not on live, not in production)';

    public function handle(Fawaterk $fawaterk): int
    {
        if (Environment::fromConfig(config('fawaterk.environment')) === Environment::Live || $this->laravel->environment('production')) {
            $this->error('fawaterk:simulate is refused when FAWATERK_ENV=live or APP_ENV=production.');

            return self::FAILURE;
        }

        $payment = Ledger::newPayment()->newQuery()->where('uuid', $this->text('payment'))->first();

        if ($payment === null || $payment->intent_key === null) {
            $this->error('No payment with that uuid and an intent key.');

            return self::FAILURE;
        }

        if ($payment->environment !== Environment::fromConfig(config('fawaterk.environment'))->value) {
            $this->error("This payment belongs to the {$payment->environment} environment; it is never touched from here.");

            return self::FAILURE;
        }

        // Show where the change lands: a local .env can point at a live database.
        $connection = $payment->getConnectionName() ?? (string) config('database.default');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");
        $this->line(sprintf('Database: %s (%s%s)', $connection, is_string($database) ? $database : '?', is_string($host) ? ' on '.$host : ''));

        if (! $this->option('force') && ! $this->confirm("Change this payment in the [{$connection}] database?")) {
            return self::FAILURE;
        }

        $type = $this->text('type');
        $transactionId = $payment->fawaterk_transaction_id ?? random_int(100000, 999999);
        $fake = $fawaterk->fake();
        // A method the commission check can resolve (merchant pays), so "auto" works too.
        $fake->setPaymentMethods(new PaymentMethod($payment->payment_method_id ?? 999999, 'Simulated', null, false, false));
        $fake->setTransaction(new TransactionData(
            intentKey: $payment->intent_key,
            transactionId: $transactionId,
            paid: $type === 'paid' || $payment->status->isPaid(),
            totalMinor: $payment->amount_minor,
            currency: $payment->currency,
            commissionMinor: 0,
            paymentMethod: 'Simulated',
            statusText: $type === 'paid' ? 'paid' : 'unpaid',
            paidAt: null,
        ));

        $webhook = match ($type) {
            'paid' => SignedWebhook::paid($payment->intent_key, $transactionId, 'Simulated'),
            'failed' => SignedWebhook::failed($payment->intent_key, $transactionId, 'Simulated'),
            'cancel' => SignedWebhook::cancel(random_int(100000, 999999), 'Simulated', $payment->intent_key),
            'refund' => $this->refund($fake, $transactionId, $payment->paid_amount_minor ?? $payment->amount_minor),
            default => null,
        };

        if ($webhook === null) {
            $this->error('The type must be paid, failed, cancel or refund.');

            return self::FAILURE;
        }

        $request = Request::create('/fawaterk/simulate', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
        $response = $this->laravel->make(WebhookController::class)($request, $webhook->segment());

        $payment->refresh();
        $this->line("HTTP {$response->getStatusCode()}; the payment is now {$payment->status->value}.");

        return $response->getStatusCode() === 200 ? self::SUCCESS : self::FAILURE;
    }

    private function refund(FawaterkFake $fake, int $transactionId, int $defaultMinor): SignedWebhook
    {
        $amount = $this->option('amount');
        $minor = is_string($amount) && $amount !== '' ? Money::toMinor($amount) : $defaultMinor;

        $fake->setRefundPage(new RefundPage(1, 1, [
            new RefundItem(random_int(100000, 999999), '3', $transactionId, $minor, 'approved'),
        ]));

        return SignedWebhook::refund($transactionId, Money::format($minor));
    }

    private function text(string $argument): string
    {
        $value = $this->argument($argument);

        return is_string($value) ? $value : '';
    }
}

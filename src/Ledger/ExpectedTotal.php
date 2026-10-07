<?php

namespace BiztechEG\Fawaterk\Ledger;

use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Methods\MethodResolver;
use Closure;

/**
 * What the re-read total must be, per commission mode:
 *
 * - merchant: the checkout amount (you absorb Fawaterk's commission)
 * - customer: the checkout amount plus the re-read commission
 * - auto: plus the commission only when the method used charges it to the
 *   customer (commission_on_customer = 1); a method that is not in the
 *   list gives null, which is treated as a mismatch. A failure to fetch the
 *   list is thrown, so the webhook answers 503 and the payment is retried.
 *
 * @internal
 */
final class ExpectedTotal
{
    public const MODES = ['merchant', 'customer', 'auto'];

    /**
     * @param  Closure(): MethodResolver  $methods
     */
    public function __construct(private readonly string $mode, private readonly Closure $methods)
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new ConfigurationException('FAWATERK_COMMISSION must be merchant, customer or auto.');
        }
    }

    public function for(FawaterkPayment $payment, TransactionData $data): ?int
    {
        $commission = $data->commissionMinor ?? 0;

        return match ($this->mode) {
            'merchant' => $payment->amount_minor,
            'customer' => $payment->amount_minor + $commission,
            default => $this->auto($payment, $data, $commission),
        };
    }

    private function auto(FawaterkPayment $payment, TransactionData $data, int $commission): ?int
    {
        $methods = ($this->methods)();
        $method = $payment->payment_method_id !== null
            ? $methods->findById($payment->payment_method_id)
            : ($data->paymentMethod === null ? null : $methods->findByName($data->paymentMethod));

        return match ($method?->commissionOnCustomer) {
            true => $payment->amount_minor + $commission,
            false => $payment->amount_minor,
            null => null,
        };
    }
}

<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A signed cancel webhook arrived. It cannot be tied to a payment reliably, so it only schedules a re-check.
 * Informational: never act on it. $payment is the row its unsigned hint pointed at, if any.
 */
final class PaymentCancelReported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly ?FawaterkPayment $payment, public readonly string $referenceId, public readonly string $paymentMethod) {}
}

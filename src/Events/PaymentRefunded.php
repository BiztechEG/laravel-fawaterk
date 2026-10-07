<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A refund was verified against Fawaterk's refund list and added to refunded_amount_minor.
 */
final class PaymentRefunded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment, public readonly int $amountMinor, public readonly string $refundId) {}
}

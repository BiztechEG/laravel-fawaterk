<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A signed refund webhook could not be verified against Fawaterk's refund list. Informational: do not act on it
 * without checking the dashboard. $payment is null when no payment has that transaction id (it can be an invoice
 * id).
 */
final class PaymentRefundReported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly ?FawaterkPayment $payment, public readonly int $transactionId, public readonly string $amount, public readonly string $currency) {}
}

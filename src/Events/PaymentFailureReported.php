<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A signed failed webhook arrived for this payment. It is signed with the same string as the paid one, so it
 * proves nothing: the status does not change, its link or code is no longer reused, and the payment is re-checked.
 * Informational: never cancel an order on it. Sent once per payment.
 */
final class PaymentFailureReported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment, public readonly ?int $transactionId) {}
}

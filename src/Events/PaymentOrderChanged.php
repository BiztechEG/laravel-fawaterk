<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Paid, but the payable changed since checkout (its fingerprint differs) or it no longer exists. Blocking:
 * PaymentPaid is not sent.
 */
final class PaymentOrderChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment, public readonly bool $payableMissing = false) {}
}

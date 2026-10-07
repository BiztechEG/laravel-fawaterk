<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fawaterk confirmed the payment on a re-read and no blocking flag was raised. Deliver what was bought, then
 * call $payment->markFulfilled(). It is sent again by reconcile until then, so make your listener idempotent.
 * $late is true when the payment arrived after the row was failed or expired.
 */
final class PaymentPaid
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment, public readonly bool $late = false) {}
}

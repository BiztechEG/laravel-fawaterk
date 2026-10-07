<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Another payment for the same payable and purpose was already paid. Blocking: PaymentPaid is not sent; refund
 * one of them.
 */
final class PaymentPaidTwice
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment) {}
}

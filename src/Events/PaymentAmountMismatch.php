<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Paid, but not the expected amount (or currency). Blocking: PaymentPaid is not sent. $expectedMinor is null
 * when the expected total could not be worked out (commission mode auto with an unknown method).
 */
final class PaymentAmountMismatch
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment, public readonly ?int $expectedMinor, public readonly int $paidMinor) {}
}

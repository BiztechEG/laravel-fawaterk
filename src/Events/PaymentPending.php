<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fawaterk started a transaction for the intent (for example a code was issued or a card attempt began).
 * Informational.
 */
final class PaymentPending
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment) {}
}

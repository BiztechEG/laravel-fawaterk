<?php

namespace BiztechEG\Fawaterk\Events;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A paid payment is still not fulfilled after the configured time or attempts. Sent once per payment.
 */
final class PaymentUnfulfilled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly FawaterkPayment $payment) {}
}

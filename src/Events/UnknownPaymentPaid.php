<?php

namespace BiztechEG\Fawaterk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A signed paid webhook for an intent that is not in the ledger, and a re-read says it is paid. It can belong to
 * another integration on the same Fawaterk account.
 */
final class UnknownPaymentPaid
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly string $intentKey, public readonly int $transactionId) {}
}

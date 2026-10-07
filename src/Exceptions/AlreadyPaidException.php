<?php

namespace BiztechEG\Fawaterk\Exceptions;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;

/**
 * The payable already has a paid payment for this purpose, so no new link or
 * code was created.
 */
final class AlreadyPaidException extends FawaterkException
{
    public function __construct(public readonly FawaterkPayment $payment)
    {
        parent::__construct('This payable is already paid for this purpose.');
    }
}

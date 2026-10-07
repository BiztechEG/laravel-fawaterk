<?php

namespace BiztechEG\Fawaterk\Exceptions;

/**
 * Fawaterk::checkout() was called inside an open database transaction. If
 * that transaction rolled back, the customer would keep a payment link or
 * code the ledger no longer knows about. Call it after your transaction
 * commits. (Fawaterk::fake() is exempt, so tests that wrap everything in a
 * transaction still work.)
 */
final class CheckoutInTransactionException extends FawaterkException
{
    public function __construct()
    {
        parent::__construct('Call Fawaterk::checkout() outside a database transaction (after your transaction commits).');
    }
}

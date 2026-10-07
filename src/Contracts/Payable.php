<?php

namespace BiztechEG\Fawaterk\Contracts;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Data\CreateTransaction;

/**
 * Anything your app sells through Fawaterk: an order, an invoice, a top-up.
 * Implement it on an Eloquent model (and use HasFawaterkPayments).
 */
interface Payable
{
    /**
     * The transaction to create, built on the server from your own data. The
     * package sets the method, the redirect URLs and the due date from the
     * profile, and replaces anything you set for them.
     */
    public function toFawaterkCheckout(CheckoutContext $context): CreateTransaction;

    /**
     * The facts that define what this payment buys (for example the plan id,
     * the amount and the user id). If they differ when the payment arrives,
     * the payment is flagged order_changed instead of PaymentPaid. Use values
     * that read back the same from your database (ints, strings, enums, dates).
     *
     * @return array<string, mixed>
     */
    public function fawaterkFingerprint(): array;
}

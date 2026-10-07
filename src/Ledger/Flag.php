<?php

namespace BiztechEG\Fawaterk\Ledger;

/**
 * Flags on a ledger row.
 *
 * Blocking flags settle the row: its event fires once, PaymentPaid is never
 * sent for it, and reconcile leaves it alone. Informational flags never stop
 * fulfilment or refund handling.
 */
enum Flag: string
{
    // Blocking.
    case AmountMismatch = 'amount_mismatch';
    case PaidTwice = 'paid_twice';
    case OrderChanged = 'order_changed';
    case PayableMissing = 'payable_missing';

    // Informational.
    case LatePayment = 'late_payment';
    case Refunded = 'refunded';
    case RefundUnverified = 'refund_unverified';
    case CancelReported = 'cancel_reported';
    case FailureReported = 'failure_reported';
    case UnfulfilledAlerted = 'unfulfilled_alerted';

    public function isBlocking(): bool
    {
        return in_array($this, [self::AmountMismatch, self::PaidTwice, self::OrderChanged, self::PayableMissing], true);
    }
}

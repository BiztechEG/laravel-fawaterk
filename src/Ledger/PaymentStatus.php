<?php

namespace BiztechEG\Fawaterk\Ledger;

/**
 * Ledger states.
 *
 * created, pending, failed and expired are soft: any of them becomes paid when
 * a re-read says paid. failed means only that the checkout could not be
 * created: a failed webhook cannot be proven (it is signed like the paid one),
 * so it is a re-check trigger and a flag, never a state. paid never goes back;
 * it becomes refunded only when the verified refunds reach the paid amount.
 * There is no "cancelled" state: a cancel webhook cannot be proven, so it only
 * triggers a re-check.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function isPaid(): bool
    {
        return $this === self::Paid || $this === self::Refunded;
    }

    /**
     * Not paid, and not yet expired.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Created, self::Pending, self::Failed];
    }

    /**
     * Rows reconcile re-reads: open ones, and expired ones for a while, since a
     * late payment can still arrive.
     *
     * @return list<self>
     */
    public static function checkable(): array
    {
        return [...self::open(), self::Expired];
    }
}

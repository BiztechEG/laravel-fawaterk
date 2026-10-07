<?php

namespace BiztechEG\Fawaterk\Refunds;

use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use Closure;

/**
 * Verifies refunds against POST /api/v3/refund/index, the only source that
 * can confirm one. The list is paginated with no filter, so the scan is
 * bounded. An entry counts when it points at our Fawaterk transaction id, is
 * a transaction refund, and is approved; each entry id is counted once.
 *
 * Only the transaction types are accepted: invoices and payment links have
 * their own id sequences, so their refunds may carry the number of one of our
 * transactions. The API gives "Transaction"; "3" is the documented number.
 *
 * @internal
 */
final class RefundVerifier
{
    private const TRANSACTION_TYPES = ['transaction', '3'];

    private const APPROVED = ['approved', '1'];

    /**
     * @param  Closure(): FawaterkClient  $client
     */
    public function __construct(
        private readonly Closure $client,
        private readonly PaymentRecorder $recorder,
        private readonly int $maxPages = 5,
    ) {}

    /**
     * Counts every verified refund of this payment not counted before.
     */
    public function verify(FawaterkPayment $payment): RefundResult
    {
        $transactionId = $payment->fawaterk_transaction_id;

        if ($transactionId === null) {
            return new RefundResult(0);
        }

        $applied = 0;

        for ($number = 1; $number <= max(1, $this->maxPages); $number++) {
            $page = ($this->client)()->refundPage($number);

            foreach ($page->items as $item) {
                if ($this->counts($item, $transactionId) && $this->recorder->applyRefund($payment, $item)) {
                    $applied++;
                }
            }

            if (! $page->hasMore()) {
                break;
            }
        }

        return new RefundResult($applied);
    }

    /**
     * One read of the refund list, counting every verified refund of any of
     * our payments not counted before (a refund whose webhook was lost or
     * reached another URL). The list comes newest first, so the first pages
     * hold the recent ones.
     *
     * @param  Closure(int): ?FawaterkPayment  $paymentFor  our payment with this Fawaterk transaction id
     * @param  (Closure(): bool)|null  $stop  asked before each further page (the run's time is up)
     */
    public function scanList(Closure $paymentFor, ?Closure $stop = null): RefundResult
    {
        $applied = 0;

        for ($number = 1; $number <= max(1, $this->maxPages); $number++) {
            if ($number > 1 && $stop !== null && $stop()) {
                break;
            }

            $page = ($this->client)()->refundPage($number);

            foreach ($page->items as $item) {
                $payment = $this->counts($item, $item->refundableId) ? $paymentFor($item->refundableId) : null;

                if ($payment !== null && $this->recorder->applyRefund($payment, $item, announced: false)) {
                    $applied++;
                }
            }

            if (! $page->hasMore()) {
                break;
            }
        }

        return new RefundResult($applied);
    }

    private function counts(RefundItem $item, int $transactionId): bool
    {
        return $item->refundableId === $transactionId
            && in_array(strtolower($item->refundableType), self::TRANSACTION_TYPES, true)
            && in_array(strtolower($item->status), self::APPROVED, true);
    }
}

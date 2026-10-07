<?php

namespace BiztechEG\Fawaterk\Ledger;

use ArrayObject;
use BiztechEG\Fawaterk\Contracts\Payable;
use BiztechEG\Fawaterk\Data\Money;
use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentExpired;
use BiztechEG\Fawaterk\Events\PaymentFailureReported;
use BiztechEG\Fawaterk\Events\PaymentOrderChanged;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentPaidTwice;
use BiztechEG\Fawaterk\Events\PaymentPending;
use BiztechEG\Fawaterk\Events\PaymentRefunded;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\PaymentUnfulfilled;
use BiztechEG\Fawaterk\Exceptions\FawaterkException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use Carbon\CarbonInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * The only code that changes ledger rows after checkout.
 *
 * Every change runs in a DB transaction that locks all rows of the same
 * (payable, purpose), ordered by id, so concurrent webhooks, reconcile runs and
 * checkouts see one consistent state. Events are sent after the commit, and a
 * failing listener never undoes the ledger. This class cannot be replaced.
 *
 * @internal
 */
final class PaymentRecorder
{
    public const FULFILMENT_MODES = ['manual', 'after_listeners'];

    /** Minutes before reconcile sends an alert again that a crash left unsent. */
    private const ALERT_RETRY_MINUTES = 10;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly ExpectedTotal $expected,
        private readonly DateTimeZone $providerTimezone,
        private readonly string $fulfilment = 'manual',
        private readonly int $firstRedispatchMinutes = 10,
    ) {}

    /**
     * Apply a fresh re-read. This is the only way a row becomes paid.
     *
     * @param  int|null  $signedTransactionId  the transaction id a verified paid webhook signed
     *
     * @throws FawaterkException when the expected total cannot be worked out right now
     */
    public function applyReRead(FawaterkPayment $payment, TransactionData $data, ?int $signedTransactionId = null): FawaterkPayment
    {
        // Worked out before the locks: it may call Fawaterk (commission "auto"),
        // and a failure there must be retried, never turned into a mismatch.
        $expected = $data->paid && ! $payment->status->isPaid() ? $this->expected->for($payment, $data) : null;

        return $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) use ($data, $signedTransactionId, $expected) {
            if ($row->intent_key !== null && $row->intent_key !== $data->intentKey) {
                throw new UnexpectedResponseException('A re-read for another intent was applied to this payment.');
            }

            $row->last_checked_at = Carbon::now();

            if ($data->transactionId > 0 && $row->fawaterk_transaction_id === null) {
                $row->fawaterk_transaction_id = $data->transactionId;
            }

            if (! $data->paid) {
                $this->applyNotPaid($row, $data, $after);
            } elseif (! $row->status->isPaid()) {
                // The paid transaction's id wins over an earlier attempt's.
                $paidId = $data->transactionId > 0 ? $data->transactionId : $signedTransactionId;
                if ($paidId !== null && $paidId > 0) {
                    $row->fawaterk_transaction_id = $paidId;
                }

                $this->applyPaid($row, $rows, $data, $expected, $after);
            }

            $row->save();

            return $row;
        }, group: true);
    }

    /**
     * A verified failed webhook. It is signed with the same string as the paid
     * one, so it proves nothing and never changes the status: the row is
     * flagged, which stops reuse of its link or code, and re-checked soon.
     */
    public function reportFailure(FawaterkPayment $payment, ?int $transactionId = null): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) use ($transactionId) {
            if ($row->status->isPaid()) {
                return $row;
            }

            if ($transactionId !== null && $transactionId > 0 && $row->fawaterk_transaction_id === null) {
                $row->fawaterk_transaction_id = $transactionId;
            }

            $new = ! $row->hasFlag(Flag::FailureReported);
            $row->addFlag(Flag::FailureReported);
            $row->next_check_at = Carbon::now();
            $row->save();

            if ($new) {
                $after[] = fn () => $this->dispatch(new PaymentFailureReported($row, $transactionId));
            }

            return $row;
        });
    }

    /**
     * The caller confirmed (by a re-read or a 422) that the row is not paid.
     */
    public function markExpired(FawaterkPayment $payment): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) {
            if (in_array($row->status, PaymentStatus::open(), true)) {
                $row->status = PaymentStatus::Expired;
                // A late payment can still arrive: reconcile keeps looking, daily.
                $row->next_check_at = Carbon::now()->addDay();
                $row->removeFlag(Flag::CancelReported);
                $row->save();
                $after[] = fn () => $this->dispatch(new PaymentExpired($row));
            }

            return $row;
        });
    }

    /**
     * A verified paid or failed webhook that did not end paid: Fawaterk may not
     * show the payment yet, or was unreachable. Look again soon. It only ever
     * moves the check earlier, so replayed webhooks cannot postpone it.
     */
    public function recheckSoon(FawaterkPayment $payment, int $minutes = 2): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row) use ($minutes) {
            $at = Carbon::now()->addMinutes($minutes);

            if (in_array($row->status, PaymentStatus::checkable(), true) && ($row->next_check_at === null || $row->next_check_at->gt($at))) {
                $row->next_check_at = $at;
                $row->save();
            }

            return $row;
        });
    }

    /**
     * Re-check the row soon. A cancel webhook can do nothing else.
     */
    public function scheduleRecheck(FawaterkPayment $payment, bool $cancelReported = false): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row) use ($cancelReported) {
            if (! $row->status->isPaid() && $row->status !== PaymentStatus::Expired) {
                $row->next_check_at = Carbon::now();

                if ($cancelReported) {
                    $row->addFlag(Flag::CancelReported);
                }

                $row->save();
            }

            return $row;
        });
    }

    /**
     * Record a refund verified against Fawaterk's refund list. Each refund id is
     * counted once. Returns false when it was already counted.
     *
     * $announced is false for the daily list scan: unless a refund
     * webhook of that amount is already waiting for it, the id is kept in
     * refund_unannounced, for its webhook to use if it arrives later.
     */
    public function applyRefund(FawaterkPayment $payment, RefundItem $item, bool $announced = true): bool
    {
        return $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) use ($item, $announced) {
            $refundId = (string) $item->id;
            $counted = $row->refund_ids ?? [];

            if (! $row->status->isPaid() || array_key_exists($refundId, $counted)) {
                return false;
            }

            if (! $announced && ! $this->refundAwaited($row, $item->amountMinor)) {
                $row->refund_unannounced = [...($row->refund_unannounced ?? []), $refundId];
            }

            $counted[$refundId] = $item->amountMinor;
            $row->refund_ids = $counted;
            $row->refunded_amount_minor += $item->amountMinor;
            $row->addFlag(Flag::Refunded);

            if ($row->refunded_amount_minor >= (int) $row->paid_amount_minor) {
                $row->status = PaymentStatus::Refunded;
                $row->next_dispatch_at = null;
            }

            $row->save();
            $after[] = fn () => $this->dispatch(new PaymentRefunded($row, $item->amountMinor, $refundId));

            return true;
        });
    }

    /**
     * Watch a verified refund webhook until refund/index confirms it.
     *
     * Each webhook gets its own window. A refund of the same amount counted
     * before it arrived cannot confirm it ("known"), so a second refund of an
     * equal amount waits for its own refund id. A replay of a refund still
     * being watched changes nothing. A refund for a row we think unpaid means
     * it was paid: that row is re-checked too.
     *
     * @param  string  $key  "amountMinor|CUR"
     */
    public function watchRefund(FawaterkPayment $payment, string $key, int $windowHours): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row) use ($key, $windowHours) {
            $pending = $row->refund_pending ?? [];

            foreach ($pending as $entry) {
                if ($entry['key'] === $key && empty($entry['report'])) {
                    return $row;
                }
            }

            [$minor, $currency] = self::refundKey($key);

            // The daily list scan already counted a refund of this amount that no webhook announced: this is its
            // webhook, late or resent.
            if ($currency === $row->currency) {
                $unannounced = $row->refund_unannounced ?? [];

                foreach ($unannounced as $index => $id) {
                    if ((int) (($row->refund_ids ?? [])[$id] ?? -1) === $minor) {
                        unset($unannounced[$index]);
                        $row->refund_unannounced = $unannounced === [] ? null : array_values($unannounced);
                        $row->save();

                        return $row;
                    }
                }
            }

            $now = Carbon::now();
            $until = $now->copy()->addHours($windowHours);
            $known = $currency !== $row->currency ? [] : array_keys(array_filter(
                $row->refund_ids ?? [],
                fn (int $amount) => $amount === $minor,
            ));

            $pending[] = ['key' => $key, 'until' => self::utc($until), 'known' => array_map('strval', $known)];
            $row->refund_pending = $pending;
            $row->refund_watch_until = $row->refund_watch_until?->gt($until) ? $row->refund_watch_until : $until;
            $row->next_refund_check_at = $now;

            if (! $row->status->isPaid()) {
                $row->next_check_at = $now;
            }

            $row->save();

            return $row;
        });
    }

    /**
     * After a refund/index scan ($scanned is false when it failed; new refund
     * ids were counted by then). A watched refund is confirmed by a counted
     * refund id of its amount that it did not already know. One whose window
     * ended unconfirmed (a day later while scans keep failing) is flagged and
     * reported once. The list is scanned until the last window ends, even with
     * nothing pending: another refund of an equal amount may still appear.
     */
    public function refundChecked(FawaterkPayment $payment, bool $scanned, int $windowHours): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) use ($scanned, $windowHours) {
            $now = Carbon::now();
            $keep = [];
            $report = [];

            foreach ($row->refund_pending ?? [] as $entry) {
                if (! empty($entry['report'])) {
                    $keep[] = $entry; // reported, not sent yet

                    continue;
                }

                if ($this->refundConfirmed($row, $entry)) {
                    continue;
                }

                $until = Carbon::parse($entry['until']);

                if ($now->gte($scanned ? $until : $until->copy()->addDay())) {
                    $entry['report'] = true;
                    $report[] = $entry;
                }

                $keep[] = $entry;
            }

            $open = array_values(array_filter($keep, fn (array $entry) => empty($entry['report'])));
            $horizon = $row->refund_watch_until;
            $row->refund_pending = $keep === [] ? null : $keep;

            if ($open === [] && ($horizon === null || $now->gte($horizon))) {
                $row->refund_watch_until = null;
                $row->next_refund_check_at = null;
            } else {
                $row->next_refund_check_at = $scanned ? self::nextRefundCheck($now, $open, $horizon ?? $now, $windowHours) : $now->copy()->addMinutes(5);
            }

            if ($report !== []) {
                $row->addFlag(Flag::RefundUnverified);
                $this->alert($row, $this->refundReports($row, $report), $after, $report);
            }

            $row->save();

            return $row;
        });
    }

    public function flagUnverifiedRefund(FawaterkPayment $payment): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row) {
            $row->addFlag(Flag::RefundUnverified);
            $row->save();

            return $row;
        });
    }

    /**
     * After a reconcile check: when to look again. A pending cancel report is
     * cleared only when a re-read actually happened.
     */
    public function checked(FawaterkPayment $payment, ?CarbonInterface $nextCheckAt, bool $reRead = true): FawaterkPayment
    {
        return $this->locked($payment, function (FawaterkPayment $row) use ($nextCheckAt, $reRead) {
            $row->last_checked_at = Carbon::now();
            $row->next_check_at = in_array($row->status, PaymentStatus::checkable(), true) ? $nextCheckAt : null;

            if ($reRead) {
                $row->removeFlag(Flag::CancelReported);
            }
            $row->save();

            return $row;
        });
    }

    /**
     * Raise PaymentUnfulfilled for a paid payment that is still not fulfilled.
     * Once per payment; true only the first time.
     */
    public function flagUnfulfilled(FawaterkPayment $payment): bool
    {
        return $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) {
            if ($row->hasFlag(Flag::UnfulfilledAlerted) || $row->fulfilled_at !== null || $row->hasBlockingFlag()) {
                return false;
            }

            $row->addFlag(Flag::UnfulfilledAlerted);
            $this->alert($row, [new PaymentUnfulfilled($row)], $after);
            $row->save();

            return true;
        });
    }

    /**
     * Alerts a crash left unsent (their next_alert_at is due): the row's
     * alerts are sent again. At least once: after a crash an alert that did go
     * out may be repeated.
     */
    public function resendAlerts(FawaterkPayment $payment): void
    {
        $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) {
            if ($row->next_alert_at === null || $row->next_alert_at->isFuture()) {
                return;
            }

            $reported = array_values(array_filter($row->refund_pending ?? [], fn (array $entry) => ! empty($entry['report'])));
            $events = $this->blockingEvents($row);

            if ($row->hasFlag(Flag::UnfulfilledAlerted) && $row->fulfilled_at === null && ! $row->hasBlockingFlag()) {
                $events[] = new PaymentUnfulfilled($row);
            }

            $row->next_alert_at = null;
            $this->alert($row, [...$events, ...$this->refundReports($row, $reported)], $after, $reported);
            $row->save();
        });
    }

    /**
     * Record that the app delivered what the payment bought. Idempotent.
     * Refused, like fulfilOnce(), for a row with a blocking flag: it stays in
     * the unfulfilled lists until someone looks at it.
     */
    public function markFulfilled(FawaterkPayment $payment): void
    {
        $current = $payment->newQuery()->useWritePdo()->whereKey($payment->getKey())->first();

        if ($current !== null && $current->fulfilled_at === null && $current->hasBlockingFlag()) {
            throw new LogicException('A payment with a blocking flag cannot be marked fulfilled.');
        }

        $payment->newQuery()
            ->whereKey($payment->getKey())
            ->whereNull('fulfilled_at')
            ->whereIn('status', [PaymentStatus::Paid->value, PaymentStatus::Refunded->value])
            ->update(['fulfilled_at' => Carbon::now(), 'next_dispatch_at' => null]);

        $fresh = $payment->newQuery()->useWritePdo()->whereKey($payment->getKey())->first();

        if ($fresh === null || ! $fresh->status->isPaid()) {
            throw new LogicException('Only a paid payment can be marked fulfilled.');
        }

        $payment->fulfilled_at = $fresh->fulfilled_at;
        $payment->next_dispatch_at = $fresh->next_dispatch_at;
        $payment->syncOriginalAttributes(['fulfilled_at', 'next_dispatch_at']);
    }

    /**
     * Run the app's delivery once, under the row lock and in one transaction
     * with fulfilled_at (FawaterkPayment::fulfilOnce()). The payable is checked
     * first: an order edited or deleted since checkout is flagged, never
     * delivered.
     *
     * @param  Closure(FawaterkPayment): mixed  $callback
     */
    public function fulfilOnce(FawaterkPayment $payment, Closure $callback): bool
    {
        return $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) use ($callback) {
            if ($row->fulfilled_at !== null) {
                return false;
            }

            if ($row->status !== PaymentStatus::Paid || $row->hasBlockingFlag()) {
                throw new LogicException('Only a paid payment with no blocking flag can be fulfilled.');
            }

            if ($this->flagPayableChanged($row)) {
                $row->next_dispatch_at = null;
                $this->alert($row, $this->blockingEvents($row), $after);
                $row->save();

                return false;
            }

            $callback($row);

            $row->fulfilled_at = Carbon::now();
            $row->next_dispatch_at = null;
            $row->save();

            return true;
        });
    }

    /**
     * Send PaymentPaid (again). A failing listener is reported, never thrown:
     * reconcile sends it again until the payment is fulfilled.
     *
     * The payable is checked again under the lock before each send: an order
     * edited or deleted after payment is flagged, never delivered as edited.
     *
     * @param  bool  $onlyIfDue  a re-send (reconcile): skipped unless it is still due under the lock, so two
     *                           overlapping runs never both send it
     */
    public function dispatchPaid(FawaterkPayment $payment, bool $onlyIfDue = false): void
    {
        $send = $this->locked($payment, function (FawaterkPayment $row, Collection $rows, ArrayObject $after) use ($onlyIfDue) {
            if ($onlyIfDue && ($row->next_dispatch_at === null || $row->next_dispatch_at->isFuture())) {
                return false;
            }

            if ($row->status !== PaymentStatus::Paid || $row->fulfilled_at !== null || $row->hasBlockingFlag()) {
                // Fulfilled, refunded or settled meanwhile: out of the queue, so
                // it never takes a place in reconcile's batch again.
                if ($row->next_dispatch_at !== null) {
                    $row->next_dispatch_at = null;
                    $row->save();
                }

                return false;
            }

            if ($this->flagPayableChanged($row)) {
                $row->next_dispatch_at = null;
                $this->alert($row, $this->blockingEvents($row), $after);
                $row->save();

                return false;
            }

            $row->fulfil_attempts++;
            $row->next_dispatch_at = Carbon::now()->addMinutes($this->redispatchDelay($row->fulfil_attempts));
            $row->save();

            return true;
        });

        if (! $send) {
            return;
        }

        try {
            $this->events->dispatch(new PaymentPaid($payment, $payment->hasFlag(Flag::LatePayment)));
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($this->fulfilment === 'after_listeners') {
            $this->markFulfilled($payment);
        }
    }

    /**
     * @param  ArrayObject<int, Closure>  $after
     */
    private function applyNotPaid(FawaterkPayment $row, TransactionData $data, ArrayObject $after): void
    {
        if ($row->status->isPaid()) {
            return; // paid never goes back
        }

        if ($row->status === PaymentStatus::Created && $data->transactionId > 0) {
            $row->status = PaymentStatus::Pending;
            $after[] = fn () => $this->dispatch(new PaymentPending($row));
        }
    }

    /**
     * @param  Collection<int, FawaterkPayment>  $rows
     * @param  ArrayObject<int, Closure>  $after
     */
    private function applyPaid(FawaterkPayment $row, Collection $rows, TransactionData $data, ?int $expected, ArrayObject $after): void
    {
        $late = in_array($row->status, [PaymentStatus::Failed, PaymentStatus::Expired], true);

        $row->status = PaymentStatus::Paid;
        $row->paid_amount_minor = $data->totalMinor;
        $row->paid_at = $this->paidAt($data);
        $row->failure_reason = null;
        $row->next_check_at = null;
        // Set inside the transaction: if the process dies before PaymentPaid is
        // sent, reconcile still sends it.
        $row->next_dispatch_at = Carbon::now()->addMinutes($this->redispatchDelay(0));
        $row->removeFlag(Flag::CancelReported);

        if ($late) {
            $row->addFlag(Flag::LatePayment);
        }

        if (strtoupper($data->currency) !== $row->currency) {
            $expected = null;
        }

        $row->expected_amount_minor = $expected;

        if ($expected !== $data->totalMinor) {
            $row->addFlag(Flag::AmountMismatch);
        }

        // A row of the other environment is not real money for this one.
        if ($rows->contains(fn (FawaterkPayment $other) => $other->getKey() !== $row->getKey() && $other->environment === $row->environment && $other->status->isPaid())) {
            $row->addFlag(Flag::PaidTwice);
        }

        $this->flagPayableChanged($row);

        if (! $row->hasBlockingFlag()) {
            $after[] = fn () => $this->dispatchPaid($row);

            return;
        }

        // Settled: never re-sent.
        $row->next_dispatch_at = null;
        $this->alert($row, $this->blockingEvents($row), $after);
    }

    /**
     * The payable must still exist and match the fingerprint taken at checkout.
     * Flags the row and returns true when it does not.
     */
    private function flagPayableChanged(FawaterkPayment $row): bool
    {
        $payable = $this->payable($row);

        if ($payable === null) {
            $row->addFlag(Flag::PayableMissing);

            return true;
        }

        if (! $this->fingerprintMatches($payable, $row)) {
            $row->addFlag(Flag::OrderChanged);

            return true;
        }

        return false;
    }

    /**
     * Alerts are sent after the commit. next_alert_at is saved in the same
     * transaction as what they report and cleared once they are sent, so
     * alerts a crash left unsent are sent again by reconcile (resendAlerts()).
     *
     * @param  list<object>  $events
     * @param  ArrayObject<int, Closure>  $after
     * @param  list<array{key: string, until: string, known: list<string>, report?: bool}>  $refundEntries  the refund entries these events report
     */
    private function alert(FawaterkPayment $row, array $events, ArrayObject $after, array $refundEntries = []): void
    {
        if ($events === []) {
            return;
        }

        $marker = Carbon::now()->addMinutes(self::ALERT_RETRY_MINUTES)->startOfSecond();
        $row->next_alert_at = $marker;
        $sentReports = array_map(fn (array $entry) => $entry['key'].'@'.$entry['until'], $refundEntries);

        $after[] = function () use ($row, $events, $marker, $sentReports) {
            foreach ($events as $event) {
                $this->dispatch($event);
            }

            $this->alertsSent($row, $marker, $sentReports);
        };
    }

    /**
     * After the alerts went out: drop the refund reports they carried, and the
     * marker unless a newer alert has set another one meanwhile.
     *
     * @param  list<string>  $sentReports  "key@until" of the refund entries reported
     */
    private function alertsSent(FawaterkPayment $payment, Carbon $marker, array $sentReports): void
    {
        try {
            $this->locked($payment, function (FawaterkPayment $row) use ($marker, $sentReports) {
                $pending = array_values(array_filter(
                    $row->refund_pending ?? [],
                    fn (array $entry) => empty($entry['report']) || ! in_array($entry['key'].'@'.$entry['until'], $sentReports, true),
                ));
                $row->refund_pending = $pending === [] ? null : $pending;

                if ($row->next_alert_at?->format('Y-m-d H:i:s') === $marker->format('Y-m-d H:i:s')) {
                    $row->next_alert_at = null;
                }

                $row->save();
            });
        } catch (Throwable $e) {
            report($e); // reconcile sends them again
        }
    }

    /**
     * @param  list<array{key: string, until: string, known: list<string>, report?: bool}>  $entries
     * @return list<PaymentRefundReported>
     */
    private function refundReports(FawaterkPayment $row, array $entries): array
    {
        return array_map(function (array $entry) use ($row) {
            [$minor, $currency] = self::refundKey($entry['key']);

            return new PaymentRefundReported($row, (int) $row->fawaterk_transaction_id, Money::format($minor), $currency);
        }, $entries);
    }

    /**
     * A refund webhook of this amount (in the row's currency) is waiting for
     * the list: it will claim the refund counted now.
     */
    private function refundAwaited(FawaterkPayment $row, int $amountMinor): bool
    {
        foreach ($row->refund_pending ?? [] as $entry) {
            if (empty($entry['report']) && $entry['key'] === $amountMinor.'|'.$row->currency) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confirmed by a counted refund id of the same amount that the watch did
     * not already know when it started.
     *
     * @param  array{key: string, until: string, known: list<string>, report?: bool}  $entry
     */
    private function refundConfirmed(FawaterkPayment $row, array $entry): bool
    {
        [$minor, $currency] = self::refundKey($entry['key']);

        if ($currency !== $row->currency) {
            return false;
        }

        foreach ($row->refund_ids ?? [] as $id => $amount) {
            if ((int) $amount === $minor && ! in_array((string) $id, $entry['known'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "5000|EGP" → [5000, "EGP"].
     *
     * @return array{int, string}
     */
    private static function refundKey(string $key): array
    {
        [$minor, $currency] = array_pad(explode('|', $key, 2), 2, '');

        return [(int) $minor, $currency];
    }

    private static function utc(Carbon $time): string
    {
        return $time->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * The alerts for a row's blocking flags.
     *
     * @return list<object>
     */
    private function blockingEvents(FawaterkPayment $row): array
    {
        $events = [];

        if ($row->hasFlag(Flag::AmountMismatch)) {
            $events[] = new PaymentAmountMismatch($row, $row->expected_amount_minor, (int) $row->paid_amount_minor);
        }

        if ($row->hasFlag(Flag::PaidTwice)) {
            $events[] = new PaymentPaidTwice($row);
        }

        if ($row->hasFlag(Flag::PayableMissing)) {
            $events[] = new PaymentOrderChanged($row, payableMissing: true);
        } elseif ($row->hasFlag(Flag::OrderChanged)) {
            $events[] = new PaymentOrderChanged($row);
        }

        return $events;
    }

    /**
     * The payable, loaded from the database (morph maps respected). Null when
     * its class or its row no longer exists, or it is soft-deleted.
     *
     * Global scopes are ignored: webhooks and the scheduler run with no logged-in
     * user or tenant, and a scope must not hide a genuine payment.
     */
    private function payable(FawaterkPayment $row): ?Model
    {
        $class = Relation::getMorphedModel($row->payable_type) ?? $row->payable_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        // From the primary: the payable may live on a connection with a replica.
        $payable = (new $class)->newQueryWithoutScopes()->useWritePdo()->whereKey($row->payable_id)->first();

        if ($payable !== null && method_exists($payable, 'trashed') && $payable->trashed()) {
            return null;
        }

        return $payable;
    }

    private function fingerprintMatches(Model $payable, FawaterkPayment $row): bool
    {
        if (! $payable instanceof Payable) {
            return false;
        }

        try {
            return hash_equals($row->order_fingerprint, Fingerprint::of($payable));
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Fawaterk's paid_at, as the right instant in the app's timezone (Eloquent
     * stores the wall time it is given). Anything unreadable falls back to now:
     * a date format must never block a payment.
     */
    private function paidAt(TransactionData $data): Carbon
    {
        $value = trim((string) $data->paidAt);

        // The documented form: a naive time in Fawaterk's own timezone.
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $this->providerTimezone);
        if ($naive !== false && $naive->format('Y-m-d H:i:s') === $value) {
            return Carbon::instance($naive)->setTimezone(date_default_timezone_get());
        }

        // ISO 8601 with an explicit offset or Z.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/D', $value)) {
            $iso = date_create_immutable($value);

            if ($iso !== false) {
                return Carbon::instance($iso)->setTimezone(date_default_timezone_get());
            }
        }

        return Carbon::now();
    }

    /**
     * Refund checks back off (5, 15, 60, then 180 minutes, by the time since the
     * latest refund webhook), and a check lands exactly when each watched
     * refund's window ends and when the last window ends.
     *
     * @param  list<array{key: string, until: string, known: list<string>, report?: bool}>  $open
     */
    private static function nextRefundCheck(CarbonInterface $now, array $open, CarbonInterface $horizon, int $windowHours): CarbonInterface
    {
        $elapsed = (int) $horizon->copy()->subHours($windowHours)->diffInMinutes($now, true);

        $next = $now->copy()->addMinutes(match (true) {
            $elapsed < 15 => 5,
            $elapsed < 60 => 15,
            $elapsed < 180 => 60,
            default => 180,
        });

        foreach ([$horizon, ...array_map(fn (array $entry) => Carbon::parse($entry['until']), $open)] as $deadline) {
            if ($deadline->gt($now) && $deadline->lt($next)) {
                $next = $deadline->copy();
            }
        }

        return $next;
    }

    /**
     * Minutes before PaymentPaid is sent again, after the given number of
     * attempts. The first wait covers queue lag: a queued listener may simply
     * not have run yet.
     */
    private function redispatchDelay(int $attempts): int
    {
        $first = max(1, $this->firstRedispatchMinutes);

        return $attempts <= 1 ? $first : max($first, [2 => 15, 3 => 30, 4 => 60][$attempts] ?? 180);
    }

    private function dispatch(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Runs $callback in a transaction holding the row lock.
     *
     * Only a paid re-read ($group) locks every row of the (payable, purpose),
     * ordered by id: it needs them for paid_twice. Everything else locks its
     * own row by primary key, a record lock with no gap lock, so app code in
     * fulfilOnce() never blocks other checkouts.
     *
     * @template T
     *
     * @param  Closure(FawaterkPayment, Collection<int, FawaterkPayment>, ArrayObject<int, Closure>): T  $callback
     * @return T
     */
    private function locked(FawaterkPayment $payment, Closure $callback, bool $group = false): mixed
    {
        $connection = $payment->getConnection();

        [$result, $after] = $connection->transaction(function () use ($payment, $callback, $group) {
            $query = $payment->newQuery();

            if ($group) {
                $query->where('payable_type', $payment->payable_type)
                    ->where('payable_id', $payment->payable_id)
                    ->where('purpose', $payment->purpose)
                    ->orderBy('id');
            } else {
                $query->whereKey($payment->getKey());
            }

            /** @var Collection<int, FawaterkPayment> $rows */
            $rows = $query->lockForUpdate()->get();

            $row = $rows->first(fn (FawaterkPayment $candidate) => $candidate->getKey() === $payment->getKey());

            if ($row === null) {
                throw new LogicException('The payment row no longer exists.');
            }

            /** @var ArrayObject<int, Closure> $after */
            $after = new ArrayObject;
            $result = $callback($row, $rows, $after);

            $payment->setRawAttributes($row->getAttributes(), true);

            return [$result, $after->getArrayCopy()];
        }, 3);

        // Only the attempt that committed counts: callbacks queued inside the
        // transaction would run once per attempt when a commit is retried after
        // a deadlock. With no outer transaction they run now; inside the
        // caller's own transaction, after it commits.
        foreach ($after as $deferred) {
            if ($connection->transactionLevel() > 0) {
                $connection->afterCommit($deferred);
            } else {
                $deferred();
            }
        }

        return $result;
    }
}

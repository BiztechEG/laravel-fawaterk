<?php

namespace BiztechEG\Fawaterk\Reconcile;

use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Exceptions\AuthenticationException;
use BiztechEG\Fawaterk\Exceptions\FawaterkException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\TransactionNotFoundException;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Refunds\RefundWatcher;
use BiztechEG\Fawaterk\Support\SafeLog;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The safety net behind webhooks (fawaterk:reconcile):
 *
 * - re-reads open rows (created, pending, failed) when due, with back-off,
 *   and at once after a cancel report
 * - moves rows to expired once past expires_at plus a grace period and a
 *   re-read (or a 422) confirms they are not paid, then re-reads
 *   them daily for a week in case a late payment arrives
 * - checks watched refunds against the refund list when due
 * - sends alerts again that a crash left unsent (next_alert_at is due)
 * - sends PaymentPaid again for paid rows with no blocking flag that are not
 *   fulfilled, and raises PaymentUnfulfilled once after 30 minutes or 5
 *   attempts
 * - writes a heartbeat for fawaterk:doctor
 *
 * It only sees rows of its own account and environment, read from the
 * primary database.
 */
final class Reconciler
{
    private const READ_TIMEOUT = 10;

    private int $connectionFailures = 0;

    private Carbon $deadline;

    private Carbon $readDeadline;

    /**
     * @param  Closure(): FawaterkClient  $client
     * @param  array<string, mixed>  $config  the "fawaterk" config array
     */
    public function __construct(
        private readonly Closure $client,
        private readonly PaymentRecorder $recorder,
        private readonly RefundWatcher $refunds,
        private readonly CacheRepository $cache,
        private readonly Credentials $credentials,
        private readonly SafeLog $log,
        private readonly array $config,
    ) {}

    public function run(int $limit = 100): ReconcileReport
    {
        $report = new ReconcileReport;
        $budget = max(10, (int) ($this->config['reconcile']['max_seconds'] ?? 240));
        $this->deadline = Carbon::now()->addSeconds($budget);
        // Calls to Fawaterk get 60% of the run, so slow re-reads never starve the
        // alerts and re-sends that follow.
        $this->readDeadline = Carbon::now()->addSeconds(intdiv($budget * 6, 10));
        $this->connectionFailures = 0;

        try {
            // Each section stops early when Fawaterk is down or its time is up;
            // the rows stay due for the next run.
            foreach ($this->dueRows($limit) as $payment) {
                if ($this->mustStop()) {
                    break;
                }

                $this->check($payment, $report);
            }

            foreach ($this->refundRows($limit) as $payment) {
                if ($this->mustStop()) {
                    break;
                }

                $this->checkRefunds($payment, $report);
            }

            if (! $this->mustStop()) {
                $this->scanRefundList($report);
            }

            foreach ($this->alertRows($limit) as $payment) {
                if (Carbon::now()->gt($this->deadline)) {
                    break;
                }

                try {
                    $this->recorder->resendAlerts($payment);
                    $report->alertsResent++;
                } catch (Throwable $e) {
                    $report->errors++;
                    report($e);
                }
            }

            foreach ($this->unfulfilledRows($limit) as $payment) {
                if (Carbon::now()->gt($this->deadline)) {
                    break;
                }

                try {
                    $this->redispatch($payment, $report);
                } catch (Throwable $e) {
                    $report->errors++;
                    report($e);
                }
            }
        } finally {
            $this->cache->forever($this->heartbeatKey(), Carbon::now()->getTimestamp());
        }

        $this->log->info('reconcile', [
            'checked' => $report->checked, 'paid' => $report->paid, 'expired' => $report->expired,
            'refunds_checked' => $report->refundsChecked, 'alerts_resent' => $report->alertsResent,
            'redispatched' => $report->redispatched, 'errors' => $report->errors,
        ]);

        return $report;
    }

    public function heartbeatKey(): string
    {
        return "fawaterk:{$this->credentials->account}:{$this->credentials->environment->value}:reconcile:heartbeat";
    }

    /**
     * One run at a time per account and environment (fawaterk:reconcile).
     */
    public function lockKey(): string
    {
        return "fawaterk:{$this->credentials->account}:{$this->credentials->environment->value}:reconcile:running";
    }

    private function check(FawaterkPayment $payment, ReconcileReport $report): void
    {
        $report->checked++;
        $pastExpiry = $this->pastExpiry($payment);

        try {
            $data = $this->reRead((string) $payment->intent_key);
        } catch (TransactionNotFoundException) {
            $data = null;
        } catch (FawaterkException $e) {
            $report->errors++;
            $this->countFailure($e);
            $this->recorder->checked($payment, Carbon::now()->addMinutes(5), reRead: false);

            return;
        }

        $this->connectionFailures = 0;

        try {
            if ($data !== null) {
                $payment = $this->recorder->applyReRead($payment, $data);
            }

            if ($payment->status->isPaid()) {
                $report->paid++;

                return;
            }

            if ($payment->status === PaymentStatus::Expired) {
                $this->recorder->checked($payment, $this->nextExpiredCheck($payment));

                return;
            }

            if ($pastExpiry) {
                $this->recorder->markExpired($payment);
                $report->expired++;

                return;
            }

            $this->recorder->checked($payment, $this->nextCheck($payment));
        } catch (Throwable $e) {
            $report->errors++;
            report($e);
        }
    }

    /**
     * A refund watch that is due: scan the refund list for the payment.
     */
    private function checkRefunds(FawaterkPayment $payment, ReconcileReport $report): void
    {
        try {
            $this->refunds->check($payment);
            $this->connectionFailures = 0;
            $report->refundsChecked++;
        } catch (FawaterkException $e) {
            // Recorded by the watcher, which retries in 5 minutes.
            $report->errors++;
            $this->countFailure($e);
        } catch (Throwable $e) {
            $report->errors++;
            report($e);
        }
    }

    /**
     * Once every refund_list_scan_hours: read the refund list for refunds
     * of our paid payments that no webhook announced. Off with reread.refund
     * off (amounts would change without the list being trusted). Any failure
     * is tried again in 30 minutes and never stops the rest of the run.
     */
    private function scanRefundList(ReconcileReport $report): void
    {
        $hours = (int) ($this->config['reconcile']['refund_list_scan_hours'] ?? 24);

        if ($hours <= 0 || ! (bool) ($this->config['reread']['refund'] ?? true)) {
            return;
        }

        $paid = [PaymentStatus::Paid->value, PaymentStatus::Refunded->value];
        $key = "fawaterk:{$this->credentials->account}:{$this->credentials->environment->value}:reconcile:refund-list-scan";
        $claimed = false;

        try {
            if (! $this->rows()->whereIn('status', $paid)->whereNotNull('fawaterk_transaction_id')->exists()) {
                return;
            }

            if (! $this->cache->add($key, Carbon::now()->getTimestamp(), $hours * 3600)) {
                return;
            }

            $claimed = true;
            $report->refundsChecked += $this->refunds->scanList(
                fn (int $transactionId) => $this->rows()->whereIn('status', $paid)->where('fawaterk_transaction_id', $transactionId)->first(),
                fn () => $this->mustStop(),
            );
            $this->connectionFailures = 0;
        } catch (Throwable $e) {
            $report->errors++;
            $e instanceof FawaterkException ? $this->countFailure($e) : report($e);

            if ($claimed) {
                try {
                    $this->cache->put($key, Carbon::now()->getTimestamp(), 30 * 60);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }

    private function mustStop(): bool
    {
        return $this->connectionFailures >= 3 || Carbon::now()->gt($this->readDeadline);
    }

    /**
     * Down or rejecting our credentials is the same for every row: after three
     * in a row the run stops early.
     */
    private function countFailure(FawaterkException $e): void
    {
        $this->connectionFailures = $e instanceof ServiceUnavailableException || $e instanceof AuthenticationException
            ? $this->connectionFailures + 1
            : 0;
    }

    /**
     * Reads get one retry on connection errors, here only.
     */
    private function reRead(string $intentKey): TransactionData
    {
        try {
            return ($this->client)()->getTransaction($intentKey, self::READ_TIMEOUT);
        } catch (ServiceUnavailableException $e) {
            if ($e->status !== 0) {
                throw $e;
            }

            return ($this->client)()->getTransaction($intentKey, self::READ_TIMEOUT);
        }
    }

    private function redispatch(FawaterkPayment $payment, ReconcileReport $report): void
    {
        if ($payment->hasBlockingFlag()) {
            return;
        }

        $this->recorder->dispatchPaid($payment, onlyIfDue: true);
        $report->redispatched++;

        if ($payment->hasBlockingFlag()) {
            return; // the order changed since checkout: its own event was sent
        }

        $alertAfterMinutes = max(1, (int) ($this->config['reconcile']['alert_after_minutes'] ?? 30));
        $alertAfterAttempts = max(1, (int) ($this->config['reconcile']['alert_after_attempts'] ?? 5));
        // From when we learned of the payment, not Fawaterk's paid_at: a payment
        // found late by reconcile is not overdue on its first re-send.
        $known = $payment->last_checked_at !== null && $payment->paid_at !== null ? $payment->last_checked_at->max($payment->paid_at) : $payment->paid_at;
        $overdue = $known !== null && $known->lte(Carbon::now()->subMinutes($alertAfterMinutes));

        if ($overdue || $payment->fulfil_attempts >= $alertAfterAttempts) {
            // Checked under the lock (fulfilled meanwhile: nothing), sent once.
            $this->recorder->flagUnfulfilled($payment);
        }
    }

    private function pastExpiry(FawaterkPayment $payment): bool
    {
        $grace = max(0, (int) ($this->config['reconcile']['expiry_grace_minutes'] ?? 30));

        return $payment->expires_at !== null && $payment->expires_at->copy()->addMinutes($grace)->lte(Carbon::now());
    }

    /**
     * An expired row is re-read once a day for a week after it expired: a late
     * payment (a code paid at the last minute) still shows up.
     */
    private function nextExpiredCheck(FawaterkPayment $payment): ?CarbonInterface
    {
        if ($payment->expires_at === null) {
            return null;
        }

        $grace = max(0, (int) ($this->config['reconcile']['expiry_grace_minutes'] ?? 30));
        $until = $payment->expires_at->copy()->addMinutes($grace)->addDays(7);
        $next = Carbon::now()->addDay();

        return $next->lte($until) ? $next : null;
    }

    /**
     * Back-off by age, but never past the moment the row can expire.
     */
    private function nextCheck(FawaterkPayment $payment): CarbonInterface
    {
        $now = Carbon::now();
        $age = $payment->created_at === null ? 0 : (int) $payment->created_at->diffInMinutes($now, true);

        $next = $now->copy()->addMinutes(match (true) {
            $age < 15 => 2,
            $age < 120 => 10,
            $age < 1440 => 60,
            default => 360,
        });

        if ($payment->expires_at !== null) {
            $expiryCheck = $payment->expires_at->copy()->addMinutes(max(0, (int) ($this->config['reconcile']['expiry_grace_minutes'] ?? 30)) + 1);

            if ($expiryCheck->gt($now) && $expiryCheck->lt($next)) {
                return $expiryCheck;
            }
        }

        return $next;
    }

    /**
     * This account's and environment's rows, read from the primary.
     *
     * @return Builder<FawaterkPayment>
     */
    private function rows(): Builder
    {
        return Ledger::newPayment()->newQuery()
            ->useWritePdo()
            ->where('account', $this->credentials->account)
            ->where('environment', $this->credentials->environment->value);
    }

    /**
     * @return iterable<FawaterkPayment>
     */
    private function dueRows(int $limit): iterable
    {
        return $this->rows()
            ->whereIn('status', array_map(fn (PaymentStatus $status) => $status->value, PaymentStatus::checkable()))
            ->whereNotNull('intent_key')
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', Carbon::now())
            ->orderBy('next_check_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return iterable<FawaterkPayment>
     */
    private function refundRows(int $limit): iterable
    {
        return $this->rows()
            ->whereNotNull('next_refund_check_at')
            ->where('next_refund_check_at', '<=', Carbon::now())
            ->orderBy('next_refund_check_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return iterable<FawaterkPayment>
     */
    private function alertRows(int $limit): iterable
    {
        return $this->rows()
            ->whereNotNull('next_alert_at')
            ->where('next_alert_at', '<=', Carbon::now())
            ->orderBy('next_alert_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return iterable<FawaterkPayment>
     */
    private function unfulfilledRows(int $limit): iterable
    {
        return $this->rows()
            ->where('status', PaymentStatus::Paid->value)
            ->whereNull('fulfilled_at')
            ->whereNotNull('next_dispatch_at')
            ->where('next_dispatch_at', '<=', Carbon::now())
            ->orderBy('next_dispatch_at')
            ->limit(max(1, $limit))
            ->get();
    }
}

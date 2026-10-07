<?php

namespace BiztechEG\Fawaterk\Refunds;

use BiztechEG\Fawaterk\Exceptions\FawaterkException;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * A verified refund webhook is watched until refund/index confirms it.
 *
 * Refunds can reach the list after their webhook, so each one waits on the
 * row (refund_pending) for its own window, and the list is scanned with
 * back-off (5, 15, 60, then 180 minutes) until the last window ends. Amounts
 * only ever come from the list, each refund id once. A watched refund still
 * unconfirmed when its window ends is flagged refund_unverified and reported
 * once. A failed scan is retried in 5 minutes; a day after the window it
 * gives up and reports.
 *
 * @internal
 */
final class RefundWatcher
{
    /**
     * @param  array<string, mixed>  $config  the "fawaterk" config array
     */
    public function __construct(
        private readonly RefundVerifier $verifier,
        private readonly PaymentRecorder $recorder,
        private readonly CacheRepository $cache,
        private readonly array $config,
    ) {}

    /**
     * Start watching this refund, and scan at once unless this payment was
     * scanned in the last minute.
     *
     * @throws FawaterkException when the scan failed (the watch retries it)
     */
    public function watch(FawaterkPayment $payment, int $amountMinor, string $currency, string $account): void
    {
        $payment = $this->recorder->watchRefund($payment, $amountMinor.'|'.strtoupper($currency), $this->windowHours());

        $key = "fawaterk:{$account}:refund-scan:{$payment->uuid}";
        $seconds = max(1, (int) ($this->config['webhooks']['cooldown_seconds'] ?? 60));

        if ($this->cache->add($key, 1, $seconds)) {
            $this->check($payment);
        }
    }

    /**
     * One scan of the refund list for this payment, then settle its watch.
     *
     * @throws FawaterkException when the scan failed (it is recorded first)
     */
    public function check(FawaterkPayment $payment): void
    {
        try {
            $this->verifier->verify($payment);
        } catch (FawaterkException $e) {
            $this->recorder->refundChecked($payment, false, $this->windowHours());

            throw $e;
        }

        $this->recorder->refundChecked($payment, true, $this->windowHours());
    }

    /**
     * Read the whole refund list once for refunds no webhook announced.
     *
     * @param  Closure(int): ?FawaterkPayment  $paymentFor
     * @param  (Closure(): bool)|null  $stop  asked before each further page
     * @return int refunds counted now for the first time
     *
     * @throws FawaterkException
     */
    public function scanList(Closure $paymentFor, ?Closure $stop = null): int
    {
        return $this->verifier->scanList($paymentFor, $stop)->applied;
    }

    private function windowHours(): int
    {
        return max(1, (int) ($this->config['reconcile']['refund_watch_hours'] ?? 6));
    }
}

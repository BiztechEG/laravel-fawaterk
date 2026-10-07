<?php

namespace BiztechEG\Fawaterk\Webhooks;

use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\IntentKey;
use BiztechEG\Fawaterk\Data\Money;
use BiztechEG\Fawaterk\Environment;
use BiztechEG\Fawaterk\Events\PaymentCancelReported;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\RefundWebhookMisrouted;
use BiztechEG\Fawaterk\Events\UnknownPaymentPaid;
use BiztechEG\Fawaterk\Exceptions\FawaterkException;
use BiztechEG\Fawaterk\Exceptions\TransactionNotFoundException;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Refunds\RefundWatcher;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * A verified webhook: look the payment up by a signed id,
 * re-read it (paid: always), and apply the answer to the ledger.
 *
 * A cooldown starts only after a webhook was fully handled, so a webhook that
 * got a 503 is processed again when Fawaterk retries.
 *
 * @internal
 */
final class WebhookProcessor
{
    private const REREAD_TIMEOUT = 8;

    /** Seconds between two webhook re-reads of one intent (per kind of webhook). */
    private const REREAD_INTERVAL = 10;

    /**
     * @param  Closure(): FawaterkClient  $client
     * @param  array<string, mixed>  $config  the "fawaterk" config array
     */
    public function __construct(
        private readonly Closure $client,
        private readonly PaymentRecorder $recorder,
        private readonly RefundWatcher $refunds,
        private readonly CacheRepository $cache,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {}

    public function process(VerifiedWebhook $webhook, string $account): Outcome
    {
        return match ($webhook->type) {
            WebhookType::Paid => $this->paid($webhook, $account),
            WebhookType::Failed => $this->failed($webhook, $account),
            WebhookType::Cancel => $this->cancel($webhook, $account),
            WebhookType::Refund => $this->refund($webhook, $account),
        };
    }

    /**
     * A verified refund that reached another webhook URL. It is not
     * applied there: reconcile's daily read of the refund list counts it. Raised
     * once per refund, so the dashboard's Refund field gets fixed.
     */
    public function misrouted(VerifiedWebhook $refund, WebhookType $receivedAt, string $account): Outcome
    {
        $amount = $refund->signed['amount'];
        $currency = strtoupper($refund->signed['currency']);
        $dedupeKey = 'refund:'.$refund->transactionId().'|'.($this->amountMinor($amount) ?? 'unreadable:'.$amount).'|'.$currency;

        if (! WebhookEvent::seen($receivedAt, $dedupeKey, $account, $this->environment(), 'misrouted')) {
            $this->dispatch(new RefundWebhookMisrouted($receivedAt->value, (int) $refund->transactionId(), $amount, $currency));
        }

        return new Outcome('misrouted', 200, null, $dedupeKey);
    }

    private function paid(VerifiedWebhook $webhook, string $account): Outcome
    {
        $intentKey = (string) $webhook->intentKey();
        $payment = $this->byIntent($intentKey, $account);

        if ($payment === null) {
            return $this->checkUnknown($intentKey, $account);
        }

        if ($payment->status->isPaid()) {
            // Paid never goes back: nothing to re-read.
            return Outcome::duplicate($intentKey);
        }

        // Not paid yet: re-read, with no cooldown, but at most
        // once per intent every 10 seconds. The rest are re-checked in a minute.
        if (! $this->takeFlight('paid', $intentKey, $account)) {
            $this->recorder->recheckSoon($payment, 1);

            return new Outcome('deferred', 200, $intentKey);
        }

        $outcome = $this->reRead($payment, $intentKey, signedTransactionId: $webhook->transactionId());
        $this->recheckUnlessPaid($payment);

        return $outcome;
    }

    private function failed(VerifiedWebhook $webhook, string $account): Outcome
    {
        $intentKey = (string) $webhook->intentKey();
        $payment = $this->byIntent($intentKey, $account);

        if ($payment === null) {
            return Outcome::unknown($intentKey);
        }

        if ($payment->status->isPaid() || $this->inCooldown('failed', $intentKey, $account)) {
            return Outcome::duplicate($intentKey);
        }

        // A trigger only: it is signed like the paid webhook, so it proves nothing.
        $this->recorder->reportFailure($payment, $webhook->transactionId());

        $outcome = match (true) {
            ! (bool) ($this->config['reread']['failed'] ?? true) => Outcome::accepted($intentKey),
            ! $this->takeFlight('failed', $intentKey, $account) => new Outcome('deferred', 200, $intentKey),
            default => $this->reRead($payment, $intentKey),
        };
        $this->recheckUnlessPaid($payment);

        if ($outcome->status === 200) {
            $this->startCooldown('failed', $intentKey, $account);
        }

        return $outcome;
    }

    /**
     * A cancel is only a re-check trigger: its signed referenceId cannot be
     * tied to a payment, and its transactionKey is unsigned.
     */
    private function cancel(VerifiedWebhook $webhook, string $account): Outcome
    {
        $referenceId = $webhook->signed['referenceId'];
        $method = $webhook->signed['paymentMethod'];
        $dedupeKey = $referenceId.'|'.$method;

        if (WebhookEvent::seen(WebhookType::Cancel, $dedupeKey, $account, $this->environment())) {
            return Outcome::duplicate();
        }

        $hint = $webhook->hint('transactionKey');
        $payment = $hint === null ? null : $this->byIntent($hint, $account);

        if ($payment !== null) {
            $this->recorder->scheduleRecheck($payment, cancelReported: true);
        }

        $this->dispatch(new PaymentCancelReported($payment, $referenceId, $method));

        return Outcome::accepted(null, $dedupeKey);
    }

    /**
     * Amounts change only for refunds found in refund/index, each refund id
     * once. A refund of one of our payments is watched until the list
     * confirms it; the answer is always 200, so it is never dropped or retried
     * into a storm. Keys use minor units, so "50" and "50.00" are one refund.
     *
     * Not ours (it can be an invoice id), an unreadable amount, or
     * verification switched off: flagged and reported once per key.
     */
    private function refund(VerifiedWebhook $webhook, string $account): Outcome
    {
        $transactionId = (int) $webhook->transactionId();
        $amount = $webhook->signed['amount'];
        $currency = strtoupper($webhook->signed['currency']);
        $minor = $this->amountMinor($amount);
        $dedupeKey = $transactionId.'|'.($minor ?? 'unreadable:'.$amount).'|'.$currency;
        $payment = $transactionId > 0 ? $this->byTransactionId($transactionId, $account) : null;

        if ($this->inCooldown('refund', $dedupeKey, $account)) {
            return Outcome::duplicate($payment?->intent_key);
        }

        // Each branch starts the cooldown only once its work is saved: after a
        // database error (500), Fawaterk's retry is handled normally.
        if ($payment !== null && $minor !== null && (bool) ($this->config['reread']['refund'] ?? true)) {
            try {
                $this->refunds->watch($payment, $minor, $currency, $account);
            } catch (FawaterkException) {
                // The list is unreachable: the watch retries it in 5 minutes.
            }

            $this->startCooldown('refund', $dedupeKey, $account);

            return Outcome::accepted($payment->intent_key, $dedupeKey);
        }

        if (WebhookEvent::seen(WebhookType::Refund, $dedupeKey, $account, $this->environment())) {
            $this->startCooldown('refund', $dedupeKey, $account);

            return Outcome::duplicate($payment?->intent_key);
        }

        if ($payment !== null) {
            $this->recorder->flagUnverifiedRefund($payment);
        }

        $this->dispatch(new PaymentRefundReported($payment, $transactionId, $amount, $currency));
        $this->startCooldown('refund', $dedupeKey, $account);

        return Outcome::accepted($payment?->intent_key, $dedupeKey);
    }

    private function reRead(FawaterkPayment $payment, string $intentKey, ?int $signedTransactionId = null): Outcome
    {
        try {
            $data = ($this->client)()->getTransaction($intentKey, self::REREAD_TIMEOUT);
        } catch (TransactionNotFoundException) {
            // Fawaterk does not know an intent we created: nothing to apply.
            return new Outcome('error', 200, $intentKey);
        } catch (FawaterkException) {
            return Outcome::unavailable($intentKey);
        }

        try {
            $this->recorder->applyReRead($payment, $data, $signedTransactionId);
        } catch (FawaterkException) {
            // For example the method list (commission "auto") is unreachable.
            return Outcome::unavailable($intentKey);
        }

        return Outcome::accepted($intentKey);
    }

    /**
     * A paid or failed webhook usually means something just happened. If the
     * row did not end paid (Fawaterk not updated yet, or unreachable: 503s
     * included), reconcile looks again in 2 minutes instead of on its back-off.
     */
    private function recheckUnlessPaid(FawaterkPayment $payment): void
    {
        if (! $payment->status->isPaid()) {
            $this->recorder->recheckSoon($payment, 2);
        }
    }

    /**
     * A signed paid webhook for an intent we do not know: it may belong to
     * another integration on the account. If it is paid, raise it. When the
     * re-read fails, answer 503 so the alert is not lost.
     */
    private function checkUnknown(string $intentKey, string $account): Outcome
    {
        if ($this->inCooldown('unknown', $intentKey, $account)) {
            return Outcome::unknown($intentKey);
        }

        if (! $this->takeFlight('unknown', $intentKey, $account)) {
            return Outcome::unavailable($intentKey); // nothing recorded yet: Fawaterk retries later
        }

        try {
            $data = ($this->client)()->getTransaction($intentKey, self::REREAD_TIMEOUT);
        } catch (TransactionNotFoundException) {
            return Outcome::unknown($intentKey);
        } catch (FawaterkException) {
            return Outcome::unavailable($intentKey);
        }

        $this->startCooldown('unknown', $intentKey, $account);

        if (! $data->paid) {
            return Outcome::unknown($intentKey);
        }

        // Raised once per intent: replays are logged, not alerted again.
        $dedupeKey = 'unknown_paid:'.$intentKey;

        if (! WebhookEvent::seen(WebhookType::Paid, $dedupeKey, $account, $this->environment(), 'unknown_payment')) {
            $this->dispatch(new UnknownPaymentPaid($intentKey, $data->transactionId));
        }

        return new Outcome('unknown_payment', 200, $intentKey, $dedupeKey);
    }

    private function amountMinor(string $amount): ?int
    {
        try {
            return Money::fromApiNumber($amount);
        } catch (FawaterkException) {
            return null; // more than 2 decimals: it cannot match a refund
        }
    }

    private function inCooldown(string $kind, string $id, string $account): bool
    {
        return $this->cache->has($this->cooldownKey($kind, $id, $account));
    }

    private function startCooldown(string $kind, string $id, string $account): void
    {
        $this->cache->put($this->cooldownKey($kind, $id, $account), 1, max(1, (int) ($this->config['webhooks']['cooldown_seconds'] ?? 60)));
    }

    /**
     * At most one re-read of an intent per kind of webhook every 10 seconds:
     * a burst of replayed signed webhooks (or Fawaterk being down) is not a
     * burst of calls to Fawaterk.
     */
    private function takeFlight(string $kind, string $intentKey, string $account): bool
    {
        return $this->cache->add($this->cooldownKey($kind.'-flight', $intentKey, $account), 1, self::REREAD_INTERVAL);
    }

    private function cooldownKey(string $kind, string $id, string $account): string
    {
        return "fawaterk:{$account}:{$this->environment()}:cooldown:{$kind}:".hash('sha256', $id);
    }

    /**
     * Lookups read from the primary (a lagging replica could miss a row or
     * show an old status) and only see this environment's rows: a staging
     * process on the live database never touches live payments.
     */
    private function byIntent(string $intentKey, string $account): ?FawaterkPayment
    {
        return Ledger::newPayment()->newQuery()
            ->useWritePdo()
            ->where('account', $account)
            ->where('environment', $this->environment())
            ->where('intent_key', IntentKey::normalize($intentKey) ?? $intentKey)
            ->first();
    }

    private function byTransactionId(int $transactionId, string $account): ?FawaterkPayment
    {
        return Ledger::newPayment()->newQuery()
            ->useWritePdo()
            ->where('account', $account)
            ->where('environment', $this->environment())
            ->where('fawaterk_transaction_id', $transactionId)
            ->first();
    }

    private function environment(): string
    {
        return Environment::fromConfig($this->config['environment'] ?? null)->value;
    }

    private function dispatch(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}

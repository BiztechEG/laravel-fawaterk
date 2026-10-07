<?php

namespace BiztechEG\Fawaterk\Notifications;

use BiztechEG\Fawaterk\Data\Money;
use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentOrderChanged;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\RefundWebhookMisrouted;
use BiztechEG\Fawaterk\Events\UnknownPaymentPaid;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What an anomaly mail says, taken from the event when it fires: plain
 * strings only, so it can be queued and never reloads anything. It holds
 * ledger facts and ids, never customer data.
 */
final class AnomalyReport
{
    /**
     * @param  array<string, string>  $facts  label key => value
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $facts,
    ) {}

    public static function fromEvent(object $event): self
    {
        $kind = match (true) {
            $event instanceof PaymentOrderChanged && $event->payableMissing => 'payable_missing',
            default => Str::snake(preg_replace('/^Payment(?=[A-Z])/', '', class_basename($event)) ?? class_basename($event)),
        };

        $payment = property_exists($event, 'payment') && $event->payment instanceof FawaterkPayment ? $event->payment : null;
        $facts = $payment === null ? [] : self::paymentFacts($payment);

        if ($event instanceof PaymentAmountMismatch) {
            $facts['expected'] = $event->expectedMinor === null ? '?' : Money::format($event->expectedMinor).' '.($payment->currency ?? '');
            $facts['paid'] = Money::format($event->paidMinor).' '.($payment->currency ?? '');
        }

        if ($event instanceof UnknownPaymentPaid) {
            $facts['intent_key'] = $event->intentKey;
            $facts['transaction'] = (string) $event->transactionId;
        }

        if ($event instanceof RefundWebhookMisrouted) {
            $facts['received_at'] = $event->receivedAt;
            $facts['transaction'] = (string) $event->transactionId;
            $facts['refund'] = $event->amount.' '.$event->currency;
        }

        if ($event instanceof PaymentRefundReported) {
            $facts['transaction'] = (string) $event->transactionId;
            $facts['refund'] = $event->amount.' '.$event->currency;
        }

        $facts['time'] = Carbon::now()->utc()->format('Y-m-d H:i:s').' UTC';

        return new self($kind, array_map(fn ($value) => trim((string) $value), $facts));
    }

    /**
     * @return array<string, string>
     */
    private static function paymentFacts(FawaterkPayment $payment): array
    {
        $flags = array_keys($payment->flags ?? []);

        return array_filter([
            'environment' => $payment->environment.($payment->account === 'default' ? '' : ' / '.$payment->account),
            'payment' => $payment->uuid,
            'payable' => $payment->payable_type.' #'.$payment->payable_id,
            'purpose' => $payment->purpose,
            'status' => $payment->status->value,
            'amount' => Money::format($payment->amount_minor).' '.$payment->currency,
            'paid' => $payment->paid_amount_minor === null ? null : Money::format($payment->paid_amount_minor).' '.$payment->currency,
            'refunded' => $payment->refunded_amount_minor > 0 ? Money::format($payment->refunded_amount_minor).' '.$payment->currency : null,
            'transaction' => $payment->fawaterk_transaction_id === null ? null : (string) $payment->fawaterk_transaction_id,
            'flags' => $flags === [] ? null : implode(', ', $flags),
        ], fn ($value) => $value !== null);
    }
}

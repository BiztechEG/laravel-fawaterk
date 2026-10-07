<?php

namespace BiztechEG\Fawaterk\Results;

use BiztechEG\Fawaterk\Data\Money;
use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * What a result page may show about a payment: its state, amount and
 * reference. It holds no customer data and nothing about the payable.
 */
final class ResultPage implements JsonSerializable
{
    public const PAID = 'paid';

    /** Paid, but something needs a look (an amount mismatch, a second payment, a changed order). */
    public const UNDER_REVIEW = 'under_review';

    public const REFUNDED = 'refunded';

    /** Fawaterk has an attempt in progress (while the page still re-reads often). */
    public const PROCESSING = 'processing';

    /** An attempt was started but has not been confirmed, and the page no longer re-reads often. */
    public const UNCONFIRMED = 'unconfirmed';

    /** Waiting for the payer: a link to continue on, or a code to pay. */
    public const AWAITING_PAYMENT = 'awaiting_payment';

    /** Fawaterk reported a failed attempt; the payer needs a new checkout. */
    public const NOT_COMPLETED = 'not_completed';

    public const EXPIRED = 'expired';

    /** The checkout could not be created. */
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $paymentUuid,
        public readonly string $state,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $reference = null,
        public readonly ?CarbonInterface $referenceExpiresAt = null,
        public readonly ?CarbonInterface $paidAt = null,
        public readonly ?string $checkoutUrl = null,
        public readonly ?string $backUrl = null,
    ) {}

    /**
     * @param  bool  $live  whether the page still re-reads this payment often (and may refresh itself)
     */
    public static function of(FawaterkPayment $payment, ?string $backUrl, bool $live = true): self
    {
        $state = self::state($payment, $live);
        $isCode = $payment->reference !== null && $payment->checkout_url === null;
        $link = $state === self::AWAITING_PAYMENT && ! $isCode ? $payment->checkout_url : null;

        return new self(
            paymentUuid: $payment->uuid,
            state: $state,
            amount: Money::format($payment->status->isPaid() ? ($payment->paid_amount_minor ?? $payment->amount_minor) : $payment->amount_minor),
            currency: $payment->currency,
            reference: $state === self::FAILED ? null : $payment->reference,
            referenceExpiresAt: $state === self::AWAITING_PAYMENT && $isCode ? ($payment->expires_at ?? $payment->reference_expires_at) : null,
            paidAt: $payment->status->isPaid() ? $payment->paid_at : null,
            checkoutUrl: $link !== null && RedirectionUrls::isHttps($link) ? $link : null,
            backUrl: $backUrl,
        );
    }

    /**
     * Paid and in order: the only state an app may deliver on.
     */
    public function isPaid(): bool
    {
        return $this->state === self::PAID;
    }

    /**
     * The money arrived, even if the payment is under review or refunded since.
     */
    public function received(): bool
    {
        return in_array($this->state, [self::PAID, self::UNDER_REVIEW, self::REFUNDED], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'payment' => $this->paymentUuid,
            'state' => $this->state,
            'paid' => $this->isPaid(),
            'received' => $this->received(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'reference_expires_at' => $this->referenceExpiresAt?->format(DateTimeInterface::ATOM),
            'paid_at' => $this->paidAt?->format(DateTimeInterface::ATOM),
            'checkout_url' => $this->checkoutUrl,
            'back_url' => $this->backUrl,
        ];
    }

    private static function state(FawaterkPayment $payment, bool $live): string
    {
        $isCode = $payment->reference !== null && $payment->checkout_url === null;
        $expiry = $isCode ? ($payment->expires_at ?? $payment->reference_expires_at) : $payment->expires_at;
        $expired = $expiry !== null && $expiry->lte(Carbon::now());

        return match ($payment->status) {
            PaymentStatus::Paid => $payment->hasBlockingFlag() ? self::UNDER_REVIEW : self::PAID,
            PaymentStatus::Refunded => self::REFUNDED,
            PaymentStatus::Expired => self::EXPIRED,
            PaymentStatus::Failed => self::FAILED,
            PaymentStatus::Created, PaymentStatus::Pending => match (true) {
                $payment->hasFlag(Flag::FailureReported) => self::NOT_COMPLETED,
                $expired => self::EXPIRED,
                // A code has a transaction id (pending) from the start; a link is
                // pending once the payer started an attempt on Fawaterk's page.
                $payment->status === PaymentStatus::Pending && ! $isCode => $live ? self::PROCESSING : self::UNCONFIRMED,
                default => self::AWAITING_PAYMENT,
            },
        };
    }
}

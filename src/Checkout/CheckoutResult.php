<?php

namespace BiztechEG\Fawaterk\Checkout;

use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use DateTimeInterface;
use JsonSerializable;

/**
 * What to show the payer: a link to open, or a reference code to pay at a
 * Fawry / Aman / Masary outlet or app. Safe to return as JSON.
 */
final class CheckoutResult implements JsonSerializable
{
    public const LINK = 'link';

    public const CODE = 'code';

    public function __construct(
        public readonly string $kind,
        public readonly string $paymentUuid,
        public readonly ?string $url = null,
        public readonly ?string $referenceNumber = null,
        public readonly ?DateTimeInterface $expiresAt = null,
        public readonly bool $reused = false,
    ) {}

    public static function fromPayment(FawaterkPayment $payment, bool $reused): self
    {
        $isCode = $payment->reference !== null && $payment->checkout_url === null;

        return new self(
            kind: $isCode ? self::CODE : self::LINK,
            paymentUuid: $payment->uuid,
            url: $isCode ? null : $payment->checkout_url,
            referenceNumber: $isCode ? $payment->reference : null,
            expiresAt: $payment->expires_at?->toImmutable(),
            reused: $reused,
        );
    }

    public function isCode(): bool
    {
        return $this->kind === self::CODE;
    }

    /**
     * @return array{kind: string, payment_uuid: string, url: ?string, reference_number: ?string, expires_at: ?string, reused: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'payment_uuid' => $this->paymentUuid,
            'url' => $this->url,
            'reference_number' => $this->referenceNumber,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
            'reused' => $this->reused,
        ];
    }
}

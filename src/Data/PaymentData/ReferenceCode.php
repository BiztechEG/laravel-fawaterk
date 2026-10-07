<?php

namespace BiztechEG\Fawaterk\Data\PaymentData;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A code the customer pays at Fawry, Aman or Masary.
 */
final class ReferenceCode implements PaymentData
{
    public function __construct(
        public readonly string $referenceNumber,
        public readonly ?DateTimeImmutable $expiresAt,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'reference',
            'reference_number' => $this->referenceNumber,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
        ];
    }
}

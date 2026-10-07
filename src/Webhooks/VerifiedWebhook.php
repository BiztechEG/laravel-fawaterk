<?php

namespace BiztechEG\Fawaterk\Webhooks;

use BiztechEG\Fawaterk\Data\IntentKey;

/**
 * A webhook whose signature checked out. Only the signed fields are here as
 * facts; anything else is a hint that anyone could have edited.
 */
final class VerifiedWebhook
{
    /**
     * @param  array<string, string>  $signed  the signed fields, as sent
     * @param  array<string, string>  $hints  unsigned fields used only to find a row to re-check
     */
    public function __construct(
        public readonly WebhookType $type,
        public readonly array $signed,
        public readonly array $hints = [],
    ) {}

    /**
     * paid / failed: the signed transaction_key (Fawaterk's intent key), lowercased.
     */
    public function intentKey(): ?string
    {
        $key = $this->signed['transaction_key'] ?? null;

        return IntentKey::normalize($key);
    }

    /**
     * paid / failed: transaction_id; refund: transactionId (a transaction or an invoice id).
     */
    public function transactionId(): ?int
    {
        $id = $this->signed['transaction_id'] ?? $this->signed['transactionId'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public function hint(string $key): ?string
    {
        return $this->hints[$key] ?? null;
    }
}

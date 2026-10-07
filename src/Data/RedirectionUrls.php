<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;

/**
 * Where Fawaterk sends the customer (success, fail, pending, back) and where it
 * posts the paid webhook for this transaction. Every URL must be https.
 */
final class RedirectionUrls
{
    public function __construct(
        public readonly ?string $successUrl = null,
        public readonly ?string $failUrl = null,
        public readonly ?string $pendingUrl = null,
        public readonly ?string $backUrl = null,
        public readonly ?string $webhookUrl = null,
    ) {
        foreach ($this->toPayload() as $name => $url) {
            if (! self::isHttps($url)) {
                throw new InvalidRequestException("The {$name} must be an absolute https URL.");
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return array_filter([
            'successUrl' => $this->successUrl,
            'failUrl' => $this->failUrl,
            'pendingUrl' => $this->pendingUrl,
            'backUrl' => $this->backUrl,
            'webhookUrl' => $this->webhookUrl,
        ], fn ($value) => $value !== null);
    }

    public static function isHttps(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user']);
    }
}

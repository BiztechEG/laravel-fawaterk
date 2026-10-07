<?php

namespace BiztechEG\Fawaterk\Webhooks;

/**
 * The four webhooks Fawaterk sends. The type comes from the URL the webhook
 * was sent to, never from the body.
 */
enum WebhookType: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancel = 'cancel';
    case Refund = 'refund';

    /**
     * The URL segment. Fawaterk sends a JSON body when the URL contains
     * "_json" and a form body otherwise; both are accepted everywhere.
     */
    public static function fromSegment(string $segment): ?self
    {
        return self::tryFrom(str_ends_with($segment, '_json') ? substr($segment, 0, -5) : $segment);
    }

    public function segment(): string
    {
        return $this->value.'_json';
    }

    /**
     * The body field that holds the signature, then the fallback.
     *
     * @return list<string>
     */
    public function hashFields(): array
    {
        return match ($this) {
            self::Paid => ['transactionHashKey', 'hashKey'],
            default => ['hashKey'],
        };
    }
}

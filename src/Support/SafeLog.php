<?php

namespace BiztechEG\Fawaterk\Support;

use Psr\Log\LoggerInterface;

/**
 * The package's only logger. It writes metadata from an allow-list of keys,
 * as short scalars, and drops everything else, so bodies, headers, tokens,
 * api_key and customer data can never reach a log.
 *
 * No channel configured (fawaterk.log_channel) = no logging at all.
 *
 * @internal
 */
final class SafeLog
{
    public const KEYS = [
        'account', 'attempt', 'checked', 'command', 'count', 'errors', 'expired', 'from', 'http_status',
        'outcome', 'paid', 'payment', 'reason', 'redispatched', 'status', 'to', 'type',
    ];

    private const MAX_LENGTH = 64;

    public function __construct(private readonly ?LoggerInterface $logger) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->logger?->info('fawaterk.'.$this->clean($event), $this->filter($context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->logger?->warning('fawaterk.'.$this->clean($event), $this->filter($context));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int|string|bool>
     */
    public function filter(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                continue;
            }

            if (is_int($value) || is_bool($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = $this->clean($value);
            } elseif ($value instanceof \BackedEnum) {
                $safe[$key] = $this->clean((string) $value->value);
            }
        }

        return $safe;
    }

    private function clean(string $value): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9_.:\-]/', '', $value), 0, self::MAX_LENGTH);
    }
}

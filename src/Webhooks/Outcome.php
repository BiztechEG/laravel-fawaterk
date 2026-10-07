<?php

namespace BiztechEG\Fawaterk\Webhooks;

/**
 * What happened to a webhook: the log outcome and the HTTP status.
 *
 * @internal
 */
final class Outcome
{
    public function __construct(
        public readonly string $outcome,
        public readonly int $status = 200,
        public readonly ?string $intentKey = null,
        public readonly ?string $dedupeKey = null,
    ) {}

    public static function accepted(?string $intentKey = null, ?string $dedupeKey = null): self
    {
        return new self('accepted', 200, $intentKey, $dedupeKey);
    }

    public static function duplicate(?string $intentKey = null): self
    {
        return new self('duplicate', 200, $intentKey);
    }

    public static function unknown(?string $intentKey = null): self
    {
        return new self('unknown_payment', 200, $intentKey);
    }

    /**
     * Our side failed (Fawaterk unreachable for the re-read): 503, so
     * Fawaterk may retry, and reconcile catches it anyway.
     */
    public static function unavailable(?string $intentKey = null): self
    {
        return new self('error', 503, $intentKey);
    }
}

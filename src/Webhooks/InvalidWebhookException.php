<?php

namespace BiztechEG\Fawaterk\Webhooks;

use BiztechEG\Fawaterk\Exceptions\FawaterkException;

/**
 * A webhook that must be answered 401: its signed fields are missing or
 * malformed, or its signature does not match.
 */
final class InvalidWebhookException extends FawaterkException
{
    public const MALFORMED = 'malformed';

    public const BAD_SIGNATURE = 'bad_signature';

    public function __construct(public readonly string $outcome)
    {
        parent::__construct($outcome === self::MALFORMED
            ? 'The webhook is missing signed fields or they are malformed.'
            : 'The webhook signature does not match.');
    }
}

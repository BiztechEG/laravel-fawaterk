<?php

namespace BiztechEG\Fawaterk\Data;

/**
 * Fawaterk's intent key, the one identifier of a transaction.
 *
 * The API reference shows a UUID; the API itself gives short opaque keys such as "kd7rwmxqoltbv3ezsa". Both are
 * accepted. A UUID is lowercased, since its case carries no meaning; any other key is kept as Fawaterk gave it. At
 * most 36 characters, the width of the ledger columns.
 *
 * @internal
 */
final class IntentKey
{
    /** The key as stored and compared, or null when the value cannot be an intent key. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (Uuid::isValid($value)) {
            return strtolower($value);
        }

        return preg_match('/^[A-Za-z0-9_-]{8,36}$/D', $value) ? $value : null;
    }
}

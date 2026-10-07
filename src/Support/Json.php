<?php

namespace BiztechEG\Fawaterk\Support;

/**
 * @internal
 */
final class Json
{
    /**
     * Decodes a JSON object. Large integers stay strings instead of silently
     * becoming floats. Returns null for invalid JSON or a JSON list.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeObject(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        return $decoded;
    }
}

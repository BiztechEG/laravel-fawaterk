<?php

namespace BiztechEG\Fawaterk\Data;

/**
 * @internal
 */
final class Uuid
{
    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
    }
}

<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;

/**
 * Amounts are handled as integers of minor units (piasters). Floats are only
 * ever parsed, never compared.
 */
final class Money
{
    /** The largest whole amount accepted (15 digits), on every input path. */
    private const MAX_MAJOR = 999_999_999_999_999;

    /**
     * Your own amount ("150.00", "150", 150) → minor units. Strict: at most two
     * decimals, no sign, no thousands separators.
     */
    public static function toMinor(int|string $amount): int
    {
        if (is_int($amount)) {
            if ($amount < 0 || $amount > self::MAX_MAJOR) {
                throw new InvalidRequestException('Amounts must be zero or more, with at most 15 digits.');
            }

            return $amount * 100;
        }

        $minor = self::parseDecimal($amount);

        if ($minor === null) {
            throw new InvalidRequestException('Amounts must look like "150" or "150.25".');
        }

        return $minor;
    }

    /**
     * A number from a Fawaterk response → minor units. JSON numbers arrive as
     * int or float, some as strings; anything with more than two decimals is
     * rejected rather than rounded.
     */
    public static function fromApiNumber(mixed $value): int
    {
        $minor = match (true) {
            is_int($value) => $value >= 0 && $value <= self::MAX_MAJOR ? $value * 100 : null,
            is_float($value) => is_finite($value) ? self::parseDecimal(self::floatToString($value)) : null,
            is_string($value) => self::parseDecimal($value),
            default => null,
        };

        if ($minor === null) {
            throw new UnexpectedResponseException('Fawaterk returned an amount that is not a valid money value.');
        }

        return $minor;
    }

    /**
     * Minor units → the number Fawaterk's API expects (a JSON number).
     */
    public static function toApiNumber(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : $minor / 100;
    }

    /**
     * Minor units → "150.00" (or "-0.05").
     */
    public static function format(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad(ltrim((string) $minor, '-'), 3, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    private static function parseDecimal(string $value): ?int
    {
        $value = trim($value);

        if (! preg_match('/^(\d+)(?:\.(\d+))?$/D', $value, $m)) {
            return null;
        }

        $fraction = rtrim($m[2] ?? '', '0');

        if (strlen($fraction) > 2 || strlen(ltrim($m[1], '0')) > 15) {
            return null;
        }

        return ((int) $m[1]) * 100 + (int) str_pad($fraction, 2, '0');
    }

    private static function floatToString(float $value): string
    {
        // Six decimals are enough to expose a third decimal while hiding
        // binary noise such as 150.30000000000001.
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}

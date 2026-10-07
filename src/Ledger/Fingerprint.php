<?php

namespace BiztechEG\Fawaterk\Ledger;

use BackedEnum;
use BiztechEG\Fawaterk\Contracts\Payable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;
use UnitEnum;

/**
 * The sha256 of a payable's fingerprint in a canonical form, so the same
 * facts hash the same whether they come from a fresh model or from the
 * database: scalars as strings, dates as UTC with whole seconds, enums by
 * value, keys sorted at every level, fixed JSON flags.
 */
final class Fingerprint
{
    public static function of(Payable $payable): string
    {
        return self::hash($payable->fawaterkFingerprint());
    }

    /**
     * @param  array<array-key, mixed>  $facts
     */
    public static function hash(array $facts): string
    {
        return hash('sha256', self::canonical($facts));
    }

    /**
     * @param  array<array-key, mixed>  $facts
     */
    public static function canonical(array $facts): string
    {
        return json_encode(self::normalise($facts), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_string($value) => (string) $value,
            is_float($value) => self::float($value),
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => self::sorted($value),
            $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException('A fingerprint may hold scalars, dates, enums, arrays and Stringable values only.'),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|object
     */
    private static function sorted(array $value): array|object
    {
        $normalised = array_map(fn ($item) => self::normalise($item), $value);

        if (array_is_list($normalised)) {
            return $normalised;
        }

        ksort($normalised, SORT_STRING);

        // An object, so {"1": …} and ["…"] never encode the same.
        return (object) $normalised;
    }

    private static function float(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('A fingerprint cannot hold INF or NAN.');
        }

        // 150.5 and 150.50 are the same float: write it without trailing zeros.
        // (A decimal column read back as the string "150.50" stays a string;
        // both sides of the comparison are loaded from the database anyway.)
        $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}

<?php

namespace BiztechEG\Fawaterk\Ledger;

use InvalidArgumentException;

/**
 * Where the ledger lives: the payment model class, the connection and the
 * table names.
 */
final class Ledger
{
    /** @var class-string<FawaterkPayment> */
    private static string $paymentModel = FawaterkPayment::class;

    /**
     * Use your own model for the payments table. It must extend FawaterkPayment.
     *
     * @param  class-string<FawaterkPayment>  $class
     */
    public static function usePaymentModel(string $class): void
    {
        if (! is_a($class, FawaterkPayment::class, true)) {
            throw new InvalidArgumentException('The payment model must extend '.FawaterkPayment::class.'.');
        }

        self::$paymentModel = $class;
    }

    /**
     * @return class-string<FawaterkPayment>
     */
    public static function paymentModel(): string
    {
        return self::$paymentModel;
    }

    public static function newPayment(): FawaterkPayment
    {
        return new self::$paymentModel;
    }

    public static function connection(): ?string
    {
        $connection = config('fawaterk.ledger.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function table(string $name): string
    {
        return (string) config('fawaterk.ledger.table_prefix', 'fawaterk_').$name;
    }
}

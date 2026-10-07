<?php

namespace BiztechEG\Fawaterk\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \BiztechEG\Fawaterk\Checkout\CheckoutResult checkout(\Illuminate\Database\Eloquent\Model $payable, \BiztechEG\Fawaterk\Checkout\CheckoutContext|string|null $context = null)
 * @method static \BiztechEG\Fawaterk\Contracts\FawaterkClient client()
 * @method static \BiztechEG\Fawaterk\Methods\MethodResolver methods()
 * @method static void usePaymentModel(string $class)
 * @method static string|null resultUrl(\BiztechEG\Fawaterk\Ledger\FawaterkPayment $payment, ?\DateTimeInterface $from = null)
 * @method static void resultBackUrlUsing(?\Closure $callback)
 * @method static void resultRedirectUsing(?\Closure $callback)
 * @method static void routeNotificationsUsing(?\Closure $callback)
 * @method static \BiztechEG\Fawaterk\Testing\FawaterkFake fake()
 *
 * @see \BiztechEG\Fawaterk\Fawaterk
 */
class Fawaterk extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \BiztechEG\Fawaterk\Fawaterk::class;
    }
}

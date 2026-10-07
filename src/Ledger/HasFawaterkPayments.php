<?php

namespace BiztechEG\Fawaterk\Ledger;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Checkout\CheckoutResult;
use BiztechEG\Fawaterk\Checkout\CheckoutService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * For Eloquent models that implement Payable.
 */
trait HasFawaterkPayments
{
    /**
     * @return MorphMany<FawaterkPayment, $this>
     */
    public function fawaterkPayments(): MorphMany
    {
        return $this->morphMany(Ledger::paymentModel(), 'payable');
    }

    /**
     * Sugar for Fawaterk::checkout($this, $context).
     */
    public function fawaterkCheckout(CheckoutContext|string|null $context = null): CheckoutResult
    {
        return app(CheckoutService::class)->checkout(
            $this,
            is_string($context) ? CheckoutContext::profile($context) : ($context ?? new CheckoutContext),
        );
    }
}

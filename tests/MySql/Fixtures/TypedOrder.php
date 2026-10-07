<?php

namespace BiztechEG\Fawaterk\Tests\MySql\Fixtures;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Contracts\Payable;
use BiztechEG\Fawaterk\Data\CartItem;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\Customer;
use BiztechEG\Fawaterk\Data\Money;
use BiztechEG\Fawaterk\Ledger\HasFawaterkPayments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An order whose fingerprint holds a decimal, a date and an enum, the types
 * whose in-memory and MySQL-loaded forms differ.
 *
 * @property string $total
 * @property Carbon $placed_at
 * @property OrderKind $kind
 */
class TypedOrder extends Model implements Payable
{
    use HasFawaterkPayments;

    protected $table = 'typed_orders';

    protected $guarded = [];

    protected $casts = ['total' => 'decimal:2', 'placed_at' => 'datetime', 'kind' => OrderKind::class];

    public function toFawaterkCheckout(CheckoutContext $context): CreateTransaction
    {
        $minor = Money::toMinor((string) $this->total);

        return new CreateTransaction(
            cartTotalMinor: $minor,
            customer: new Customer('Test', 'Customer', 'customer@example.test'),
            cartItems: [new CartItem('Typed order', $minor)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fawaterkFingerprint(): array
    {
        return ['total' => $this->total, 'placed_at' => $this->placed_at, 'kind' => $this->kind];
    }
}

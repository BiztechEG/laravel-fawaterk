<?php

namespace BiztechEG\Fawaterk\Tests\Fixtures;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Contracts\Payable;
use BiztechEG\Fawaterk\Data\CartItem;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\Customer;
use BiztechEG\Fawaterk\Ledger\HasFawaterkPayments;
use Illuminate\Database\Eloquent\Model;

/**
 * A generic shop order, as a package user would write it.
 *
 * @property int $id
 * @property string $number
 * @property int $total_minor
 * @property int $user_id
 * @property string $status
 */
class Order extends Model implements Payable
{
    use HasFawaterkPayments;

    protected $guarded = [];

    protected $casts = ['total_minor' => 'integer', 'user_id' => 'integer'];

    public function toFawaterkCheckout(CheckoutContext $context): CreateTransaction
    {
        $amount = $context->purpose === 'deposit' ? intdiv($this->total_minor, 2) : $this->total_minor;

        return new CreateTransaction(
            cartTotalMinor: $amount,
            customer: new Customer('Test', 'Customer', 'customer@example.test'),
            cartItems: [new CartItem("Order {$this->number}", $amount)],
            payLoad: ['order' => $this->number],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fawaterkFingerprint(): array
    {
        return ['number' => $this->number, 'total' => $this->total_minor, 'user' => $this->user_id];
    }
}

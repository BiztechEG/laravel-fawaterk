<?php

namespace BiztechEG\Fawaterk\Tests\Fixtures;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Contracts\Payable;
use BiztechEG\Fawaterk\Data\CartItem;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\Customer;
use BiztechEG\Fawaterk\Ledger\HasFawaterkPayments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An order with a string primary key: a UUID, or a ULID in UlidOrder.
 *
 * @property string $id
 * @property int $total_minor
 */
class KeyedOrder extends Model implements Payable
{
    use HasFawaterkPayments;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'keyed_orders';

    protected $guarded = [];

    protected $casts = ['total_minor' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (KeyedOrder $order) {
            $order->id ??= static::newKey();
        });
    }

    public static function newKey(): string
    {
        return (string) Str::uuid();
    }

    public function toFawaterkCheckout(CheckoutContext $context): CreateTransaction
    {
        return new CreateTransaction(
            cartTotalMinor: $this->total_minor,
            customer: new Customer('Test', 'Customer', 'customer@example.test'),
            cartItems: [new CartItem('Keyed order', $this->total_minor)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fawaterkFingerprint(): array
    {
        return ['id' => $this->id, 'total' => $this->total_minor];
    }
}

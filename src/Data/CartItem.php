<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;

final class CartItem
{
    public function __construct(
        public readonly string $name,
        public readonly int $priceMinor,
        public readonly int $quantity = 1,
    ) {
        if (trim($name) === '') {
            throw new InvalidRequestException('Every cart item needs a name.');
        }

        if ($priceMinor <= 0) {
            throw new InvalidRequestException('Every cart item needs a price above zero.');
        }

        if ($quantity < 1) {
            throw new InvalidRequestException('Every cart item needs a quantity of at least 1.');
        }
    }

    public function totalMinor(): int
    {
        return $this->priceMinor * $this->quantity;
    }

    /**
     * @return array{name: string, price: int|float, quantity: int}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'price' => Money::toApiNumber($this->priceMinor),
            'quantity' => $this->quantity,
        ];
    }
}

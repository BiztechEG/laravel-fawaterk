<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;

/**
 * One entry of POST /api/v3/refund/index, used to verify refund webhooks.
 */
final class RefundItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $refundableType,
        public readonly int $refundableId,
        public readonly int $amountMinor,
        public readonly string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        foreach (['id', 'refundable_id'] as $key) {
            $value = $item[$key] ?? null;
            if (! (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                throw new UnexpectedResponseException('Fawaterk returned a refund without ids.');
            }
        }

        return new self(
            id: (int) $item['id'],
            refundableType: is_scalar($item['refundable_type'] ?? null) ? (string) $item['refundable_type'] : '',
            refundableId: (int) $item['refundable_id'],
            amountMinor: Money::fromApiNumber($item['refundable_amount'] ?? null),
            status: is_scalar($item['status'] ?? null) ? (string) $item['status'] : '',
        );
    }
}

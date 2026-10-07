<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;

/**
 * One entry of getTrPaymentmethods.
 */
final class PaymentMethod
{
    public function __construct(
        public readonly int $id,
        public readonly string $nameEn,
        public readonly ?string $nameAr,
        /** true: link mode (hosted checkout); false: direct dispatch (a code / wallet request). */
        public readonly bool $redirect,
        /** true: Fawaterk adds its commission to what the customer pays. null: not reported. */
        public readonly ?bool $commissionOnCustomer,
    ) {}

    /**
     * Live accounts can answer with an older shape: "paymentId" for the id, and
     * no commission_on_customer (then unknown).
     *
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        $id = $item['payment_method_id'] ?? $item['paymentId'] ?? null;
        $nameEn = $item['name_en'] ?? null;

        if (! (is_int($id) || (is_string($id) && ctype_digit($id))) || ! is_string($nameEn)) {
            throw new UnexpectedResponseException('Fawaterk returned a payment method without an id or name.');
        }

        $commission = $item['commission_on_customer'] ?? null;

        return new self(
            id: (int) $id,
            nameEn: $nameEn,
            nameAr: is_string($item['name_ar'] ?? null) ? $item['name_ar'] : null,
            redirect: in_array($item['redirect'] ?? null, ['true', true, 1, '1'], true),
            commissionOnCustomer: match (true) {
                in_array($commission, [1, '1'], true) => true,
                in_array($commission, [2, '2'], true) => false,
                default => null,
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payment_method_id' => $this->id,
            'name_en' => $this->nameEn,
            'name_ar' => $this->nameAr,
            'redirect' => $this->redirect ? 'true' : 'false',
            'commission_on_customer' => match ($this->commissionOnCustomer) {
                true => 1,
                false => 2,
                null => null,
            },
        ];
    }

    public static function normaliseName(string $name): string
    {
        return strtolower((string) preg_replace('/[\s_-]+/', '', trim($name)));
    }
}

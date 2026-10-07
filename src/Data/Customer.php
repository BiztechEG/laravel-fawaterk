<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;

final class Customer
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $address = null,
        public readonly ?string $customerUniqueId = null,
    ) {
        if (trim($firstName) === '' || trim($lastName) === '') {
            throw new InvalidRequestException('The customer needs a first and a last name.');
        }

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidRequestException('The customer email is not valid.');
        }

        if ($phone !== null && ! preg_match('/^\+?[0-9]{6,20}$/D', $phone)) {
            throw new InvalidRequestException('The customer phone must contain digits only (an optional leading +).');
        }
    }

    /**
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return array_filter([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'customer_unique_id' => $this->customerUniqueId,
        ], fn ($value) => $value !== null);
    }
}

<?php

namespace BiztechEG\Fawaterk\Data\PaymentData;

/**
 * A page the customer is sent to: Fawaterk's hosted checkout (every method, or
 * one preselected) or a provider page such as card 3-D Secure.
 */
final class PaymentLink implements PaymentData
{
    public function __construct(public readonly string $url) {}

    public function toArray(): array
    {
        return ['type' => 'link', 'url' => $this->url];
    }
}

<?php

namespace BiztechEG\Fawaterk\Data\PaymentData;

/**
 * What the customer needs in order to pay: a link, a reference code, or a
 * wallet request. Always built from the shape of Fawaterk's answer.
 */
interface PaymentData
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}

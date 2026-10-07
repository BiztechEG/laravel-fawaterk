<?php

namespace BiztechEG\Fawaterk\Data\PaymentData;

/**
 * A mobile-wallet payment request (Meeza and similar). Parsed in 1.0 so an
 * unexpected wallet answer is recognised; the wallet profile itself is 1.1.
 */
final class WalletRequest implements PaymentData
{
    public function __construct(
        public readonly string $systemReference,
        public readonly ?string $isoQr,
    ) {}

    public function toArray(): array
    {
        return ['type' => 'wallet', 'system_reference' => $this->systemReference, 'iso_qr' => $this->isoQr];
    }
}

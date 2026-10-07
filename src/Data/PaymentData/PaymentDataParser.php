<?php

namespace BiztechEG\Fawaterk\Data\PaymentData;

use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns createTransaction's `data` into PaymentData by its shape, never by what
 * was asked for: a method configured for codes can still come back as a link
 * if its settings changed at Fawaterk.
 */
final class PaymentDataParser
{
    public function __construct(private readonly DateTimeZone $providerTimezone) {}

    /**
     * @param  array<string, mixed>  $data  the response's `data` object
     */
    public function parse(array $data): PaymentData
    {
        $paymentData = $data['payment_data'] ?? null;

        // An empty payment_data says nothing; a hosted answer may still carry `url`.
        if (is_array($paymentData) && $paymentData !== []) {
            return $this->fromPaymentData($paymentData);
        }

        if (isset($data['url']) && is_string($data['url'])) {
            return $this->link($data['url']);
        }

        throw new UnexpectedResponseException('Fawaterk returned neither a payment link nor payment data.');
    }

    /**
     * @param  array<string, mixed>  $paymentData
     */
    private function fromPaymentData(array $paymentData): PaymentData
    {
        if (isset($paymentData['referenceNumber']) && (is_string($paymentData['referenceNumber']) || is_int($paymentData['referenceNumber']))) {
            $reference = (string) $paymentData['referenceNumber'];

            if (! preg_match('/^[A-Za-z0-9-]{1,64}$/D', $reference)) {
                throw new UnexpectedResponseException('Fawaterk returned a malformed reference code.');
            }

            $expiry = $paymentData['expireDate'] ?? $paymentData['expirationTime'] ?? null;

            return new ReferenceCode($reference, is_string($expiry) ? $this->date($expiry) : null);
        }

        foreach (['redirectTo', 'redirect_url', 'url'] as $key) {
            if (isset($paymentData[$key]) && is_string($paymentData[$key])) {
                return $this->link($paymentData[$key]);
            }
        }

        if (isset($paymentData['systemReference']) && (is_string($paymentData['systemReference']) || is_int($paymentData['systemReference']))) {
            $qr = $paymentData['isoQr'] ?? null;

            return new WalletRequest((string) $paymentData['systemReference'], is_string($qr) ? $qr : null);
        }

        throw new UnexpectedResponseException('Fawaterk returned payment data in an unknown shape.');
    }

    private function link(string $url): PaymentLink
    {
        if (! RedirectionUrls::isHttps($url)) {
            throw new UnexpectedResponseException('Fawaterk returned a payment link that is not https.');
        }

        return new PaymentLink($url);
    }

    /**
     * Fawaterk's expiry dates have no timezone: "2021-07-06 15:53:41" in its
     * reference, "08 Oct 2026, 04:42 PM" from the API itself. They are read
     * in the configured provider timezone. An unreadable date is treated as
     * unknown, which the ledger handles as "do not reuse". So is an impossible one ("2026-02-30"), which PHP would otherwise
     * roll over to a later date.
     */
    private function date(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        foreach (['Y-m-d H:i:s', 'd M Y, h:i A'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $this->providerTimezone);

            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }
}

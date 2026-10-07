<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Data\PaymentData\PaymentData;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentDataParser;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;

/**
 * The answer to createTransaction: the intent key plus what the customer needs
 * to pay (a link or a code).
 */
final class TransactionIntent
{
    public function __construct(
        public readonly string $intentKey,
        public readonly PaymentData $paymentData,
        public readonly ?int $expiresIn = null,
        public readonly ?string $shortUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $response  the decoded JSON body
     */
    public static function fromResponse(array $response, PaymentDataParser $parser): self
    {
        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new UnexpectedResponseException('Fawaterk returned no transaction data.');
        }

        $intentKey = IntentKey::normalize($data['intent_key'] ?? null);

        if ($intentKey === null) {
            throw new UnexpectedResponseException('Fawaterk returned no valid intent key.');
        }

        $shortUrl = $data['short_url'] ?? null;

        return new self(
            intentKey: $intentKey,
            paymentData: $parser->parse($data),
            expiresIn: isset($data['expires_in']) && is_numeric($data['expires_in']) ? (int) $data['expires_in'] : null,
            shortUrl: is_string($shortUrl) && RedirectionUrls::isHttps($shortUrl) ? $shortUrl : null,
        );
    }
}

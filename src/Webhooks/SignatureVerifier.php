<?php

namespace BiztechEG\Fawaterk\Webhooks;

use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Data\IntentKey;

/**
 * Checks Fawaterk's webhook signatures: HMAC-SHA256 of a fixed string of
 * signed fields, keyed with the vendor API key.
 *
 * - paid / failed: TransactionId={transaction_id}&TransactionKey={transaction_key}&PaymentMethod={payment_method}
 * - cancel:        referenceId={referenceId}&PaymentMethod={paymentMethod}
 * - refund:        transactionId={transactionId}&amount={amount}&currency={currency}
 * - invoice:       InvoiceId={invoice_id}&InvoiceKey={invoice_key}&PaymentMethod={payment_method}
 *
 * The signature does not cover the status or the amount of paid and failed
 * webhooks, so a verified webhook is only ever a reason to re-read the payment.
 * This class cannot be replaced or switched off.
 *
 * @internal
 */
final class SignatureVerifier
{
    /**
     * Name in the signed string => [body field, format].
     */
    private const SIGNED = [
        'paid' => ['TransactionId' => ['transaction_id', 'digits'], 'TransactionKey' => ['transaction_key', 'intent_key'], 'PaymentMethod' => ['payment_method', 'text']],
        'failed' => ['TransactionId' => ['transaction_id', 'digits'], 'TransactionKey' => ['transaction_key', 'intent_key'], 'PaymentMethod' => ['payment_method', 'text']],
        'cancel' => ['referenceId' => ['referenceId', 'digits'], 'PaymentMethod' => ['paymentMethod', 'text']],
        'refund' => ['transactionId' => ['transactionId', 'digits'], 'amount' => ['amount', 'amount'], 'currency' => ['currency', 'currency']],
        'invoice' => ['InvoiceId' => ['invoice_id', 'digits'], 'InvoiceKey' => ['invoice_key', 'key'], 'PaymentMethod' => ['payment_method', 'text']],
    ];

    public function __construct(private readonly Credentials $credentials) {}

    /**
     * @throws InvalidWebhookException
     */
    public function verify(WebhookType $type, RawPayload $payload): VerifiedWebhook
    {
        $signed = $this->signedFields($type->value, $payload);
        $hash = $this->hash($payload, $type->hashFields());

        foreach ($this->candidates($type->value, $signed) as $stringToSign) {
            if (hash_equals(self::hmac($stringToSign, $this->credentials->vendorApiKey()), $hash)) {
                return new VerifiedWebhook($type, $signed, $this->hints($type, $payload));
            }
        }

        throw new InvalidWebhookException(InvalidWebhookException::BAD_SIGNATURE);
    }

    /**
     * A legacy invoice payload reaching a paid or failed URL. The package never
     * creates invoices, so these belong to another integration on the account.
     */
    public function isInvoicePayload(WebhookType $type, RawPayload $payload): bool
    {
        return in_array($type, [WebhookType::Paid, WebhookType::Failed], true)
            && ! $payload->has('transaction_key')
            && ($payload->has('invoice_key') || $payload->has('invoice_id'));
    }

    /**
     * A refund payload reaching the paid, failed or cancel URL: the dashboard's
     * Refund field most likely holds the wrong URL. Verify it with
     * verify(WebhookType::Refund, ...) before trusting it.
     */
    public function isRefundPayload(WebhookType $type, RawPayload $payload): bool
    {
        return $type !== WebhookType::Refund
            && $payload->has('transactionId') && $payload->has('amount') && $payload->has('currency')
            && ! $payload->has('transaction_key') && ! $payload->has('referenceId');
    }

    /**
     * @throws InvalidWebhookException
     */
    public function verifyInvoice(RawPayload $payload): void
    {
        $signed = $this->signedFields('invoice', $payload);
        $hash = $this->hash($payload, ['hashKey']);

        if (! hash_equals(self::hmac($this->stringToSign('invoice', $signed), $this->credentials->vendorApiKey()), $hash)) {
            throw new InvalidWebhookException(InvalidWebhookException::BAD_SIGNATURE);
        }
    }

    public static function hmac(string $stringToSign, #[\SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $stringToSign, $key);
    }

    /**
     * @param  array<string, string>  $values  body field => value
     */
    public static function stringFor(string $kind, array $values): string
    {
        $parts = [];
        foreach (self::SIGNED[$kind] as $name => [$field]) {
            $parts[] = $name.'='.($values[$field] ?? '');
        }

        return implode('&', $parts);
    }

    /**
     * @return array<string, string>
     *
     * @throws InvalidWebhookException
     */
    private function signedFields(string $kind, RawPayload $payload): array
    {
        $signed = [];

        foreach (self::SIGNED[$kind] as [$field, $format]) {
            $value = $payload->string($field);

            if ($value === null || ! self::wellFormed($value, $format)) {
                throw new InvalidWebhookException(InvalidWebhookException::MALFORMED);
            }

            $signed[$field] = $value;
        }

        return $signed;
    }

    /**
     * @param  list<string>  $fields
     *
     * @throws InvalidWebhookException
     */
    private function hash(RawPayload $payload, array $fields): string
    {
        foreach ($fields as $field) {
            $hash = $payload->string($field);

            if ($hash !== null) {
                if (! preg_match('/^[0-9a-fA-F]{64}$/D', $hash)) {
                    throw new InvalidWebhookException(InvalidWebhookException::MALFORMED);
                }

                return strtolower($hash);
            }
        }

        throw new InvalidWebhookException(InvalidWebhookException::MALFORMED);
    }

    /**
     * @param  array<string, string>  $signed
     */
    private function stringToSign(string $kind, array $signed): string
    {
        return self::stringFor($kind, $signed);
    }

    /**
     * The strings to try. Only the refund amount has more than one: how
     * Fawaterk writes a decimal inside the signed string is not documented,
     * so "150.5", "150.50" and the text as sent are all tried.
     * Every candidate still needs the vendor key's HMAC.
     *
     * @param  array<string, string>  $signed
     * @return list<string>
     */
    private function candidates(string $kind, array $signed): array
    {
        $candidates = [$this->stringToSign($kind, $signed)];

        if ($kind === 'refund') {
            foreach (self::amountForms($signed['amount']) as $amount) {
                $candidates[] = $this->stringToSign($kind, ['amount' => $amount] + $signed);
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    private static function amountForms(string $amount): array
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $trimmed = rtrim($fraction, '0');
        $forms = [$trimmed === '' ? $whole : $whole.'.'.$trimmed];

        if (strlen($trimmed) <= 2) {
            $forms[] = $whole.'.'.str_pad($trimmed, 2, '0');
        }

        return $forms;
    }

    /**
     * @return array<string, string>
     */
    private function hints(WebhookType $type, RawPayload $payload): array
    {
        $hints = [];

        if ($type === WebhookType::Cancel) {
            $key = IntentKey::normalize($payload->string('transactionKey'));

            if ($key !== null) {
                $hints['transactionKey'] = $key;
            }
        }

        return $hints;
    }

    private static function wellFormed(string $value, string $format): bool
    {
        return match ($format) {
            'digits' => (bool) preg_match('/^\d{1,20}$/D', $value),
            'intent_key' => IntentKey::normalize($value) !== null,
            'text' => mb_check_encoding($value, 'UTF-8') && (bool) preg_match('/^[^\x00-\x1F\x7F]{1,100}$/uD', $value),
            'amount' => (bool) preg_match('/^\d{1,15}(?:\.\d{1,6})?$/D', $value),
            'currency' => (bool) preg_match('/^[A-Za-z]{2,5}$/D', $value),
            'key' => (bool) preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value),
            default => false,
        };
    }
}

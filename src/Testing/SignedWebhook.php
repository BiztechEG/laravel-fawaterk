<?php

namespace BiztechEG\Fawaterk\Testing;

use BiztechEG\Fawaterk\Webhooks\SignatureVerifier;
use BiztechEG\Fawaterk\Webhooks\WebhookType;
use RuntimeException;

/**
 * Builds correctly signed Fawaterk webhook bodies for your tests:
 *
 *     $this->postJson('/fawaterk/webhooks/paid_json', SignedWebhook::paid($payment->intent_key)->toArray());
 *
 * It signs with config('fawaterk.vendor_api_key') unless signedWith() is used.
 * Unsigned fields (with()) can be anything; the package never trusts them.
 */
final class SignedWebhook
{
    private ?string $key = null;

    /**
     * @param  array<string, int|string>  $signed  body field => value
     * @param  array<string, mixed>  $unsigned
     */
    private function __construct(
        private readonly WebhookType $type,
        private readonly string $kind,
        private readonly array $signed,
        private array $unsigned,
    ) {}

    public static function paid(string $intentKey, int $transactionId = 1001, string $paymentMethod = 'Visa-Mastercard'): self
    {
        return new self(WebhookType::Paid, 'paid', [
            'transaction_id' => $transactionId,
            'transaction_key' => $intentKey,
            'payment_method' => $paymentMethod,
        ], ['status' => 'paid']);
    }

    public static function failed(string $intentKey, int $transactionId = 1001, string $paymentMethod = 'Visa-Mastercard'): self
    {
        return new self(WebhookType::Failed, 'failed', [
            'transaction_id' => $transactionId,
            'transaction_key' => $intentKey,
            'payment_method' => $paymentMethod,
        ], ['errorMessage' => 'Declined']);
    }

    /**
     * @param  string|null  $transactionKey  the unsigned hint that points at a payment
     */
    public static function cancel(int $referenceId, string $paymentMethod = 'Aman', ?string $transactionKey = null): self
    {
        return new self(WebhookType::Cancel, 'cancel', [
            'referenceId' => $referenceId,
            'paymentMethod' => $paymentMethod,
        ], array_filter(['status' => 'EXPIRED', 'transactionKey' => $transactionKey]));
    }

    /**
     * @param  string  $amount  as Fawaterk writes it, for example "150.50"
     */
    public static function refund(int $transactionId, string $amount, string $currency = 'EGP'): self
    {
        return new self(WebhookType::Refund, 'refund', [
            'transactionId' => $transactionId,
            'amount' => $amount,
            'currency' => $currency,
        ], ['status' => 1, 'reason' => 'Customer request']);
    }

    /**
     * A legacy invoice payload (another integration on the same account).
     */
    public static function invoice(int $invoiceId = 77, string $invoiceKey = 'abcDEF123', string $paymentMethod = 'Fawry'): self
    {
        return new self(WebhookType::Paid, 'invoice', [
            'invoice_id' => $invoiceId,
            'invoice_key' => $invoiceKey,
            'payment_method' => $paymentMethod,
        ], []);
    }

    /**
     * Add or replace unsigned fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function with(array $fields): self
    {
        $clone = clone $this;
        $clone->unsigned = array_merge($this->unsigned, $fields);

        return $clone;
    }

    public function signedWith(#[\SensitiveParameter] string $key): self
    {
        $clone = clone $this;
        $clone->key = $key;

        return $clone;
    }

    public function type(): WebhookType
    {
        return $this->type;
    }

    /**
     * The path segment for this webhook: "paid_json", "failed_json", "cancel_json" or "refund_json".
     */
    public function segment(): string
    {
        return $this->type->segment();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $values = array_map(fn ($value) => (string) $value, $this->signed);
        $hash = SignatureVerifier::hmac(SignatureVerifier::stringFor($this->kind, $values), $this->key());
        $hashField = $this->type === WebhookType::Paid && $this->kind === 'paid' ? 'transactionHashKey' : 'hashKey';

        return array_merge($this->unsigned, $this->signed, [$hashField => $hash]);
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function toForm(): string
    {
        return http_build_query($this->toArray());
    }

    private function key(): string
    {
        $key = $this->key ?? (function_exists('config') ? config('fawaterk.vendor_api_key') : null);

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Set fawaterk.vendor_api_key or call signedWith() to sign test webhooks.');
        }

        return $key;
    }
}

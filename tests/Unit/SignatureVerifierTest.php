<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Webhooks\InvalidWebhookException;
use BiztechEG\Fawaterk\Webhooks\RawPayload;
use BiztechEG\Fawaterk\Webhooks\SignatureVerifier;
use BiztechEG\Fawaterk\Webhooks\VerifiedWebhook;
use BiztechEG\Fawaterk\Webhooks\WebhookType;
use PHPUnit\Framework\TestCase;

/**
 * The golden hashes were computed outside PHP, with
 * `printf '%s' "$stringToSign" | openssl dgst -sha256 -hmac test-vendor-key`.
 */
class SignatureVerifierTest extends TestCase
{
    private const INTENT = '550e8400-e29b-41d4-a716-446655440000';

    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new SignatureVerifier((new AccountRepository(['vendor_api_key' => 'test-vendor-key']))->get());
    }

    public function test_golden_vectors_for_each_formula(): void
    {
        $paid = $this->verify(WebhookType::Paid, '{"transaction_id":12345,"transaction_key":"'.self::INTENT.'","payment_method":"Visa-Mastercard","status":"paid","transactionHashKey":"7cdea7eb0dbbe574bfa2d3acbdca0f657735fdbf7ce62e903fa734780cb2ce5f"}');
        $this->assertSame(self::INTENT, $paid->intentKey());
        $this->assertSame(12345, $paid->transactionId());

        $arabic = $this->verify(WebhookType::Failed, 'transaction_id=987654321&transaction_key=550E8400-E29B-41D4-A716-446655440000&payment_method='.rawurlencode('فوري').'&hashKey=617E05C2A3D5B804BDFE5F83D8DE70DAADFB5270E72281A2504AA9E8E982CB8E');
        $this->assertSame(self::INTENT, $arabic->intentKey(), 'the key is signed as sent and used lowercased');

        $cancel = $this->verify(WebhookType::Cancel, '{"referenceId":4266311,"paymentMethod":"Aman","transactionKey":"'.strtoupper(self::INTENT).'","hashKey":"2b2556b9e0f5674e89d97f61d71272a155b198ac708bbf5b10474991ab567fca"}');
        $this->assertSame(self::INTENT, $cancel->hint('transactionKey'));
        $this->assertSame([], array_intersect_key($cancel->signed, ['transactionKey' => 1]), 'the cancel key is only a hint');

        $refund = $this->verify(WebhookType::Refund, '{"transactionId":12345,"amount":150.50,"currency":"EGP","status":1,"hashKey":"f6f78ba5557f2a006e53f0af012c1b83dc62e29754794e1923e1a0443d181ae0"}');
        $this->assertSame('150.50', $refund->signed['amount']);

        $invoice = RawPayload::parse('{"invoice_id":77,"invoice_key":"abcDEF123","payment_method":"Fawry","hashKey":"f9e72231c2ba0f5cfa8deef209e58ad73a69219b71e1b7082caf33ec26c0a86b"}');
        $this->assertTrue($this->verifier->isInvoicePayload(WebhookType::Paid, $invoice));
        $this->verifier->verifyInvoice($invoice);
    }

    public function test_the_short_intent_key_of_the_live_api_is_signed_and_kept_as_it_comes(): void
    {
        // The staging API gives short opaque keys, not UUIDs. Golden hash computed with openssl as above.
        $paid = $this->verify(WebhookType::Paid, '{"transaction_id":12345,"transaction_key":"kd7rwmxqoltbv3ezsa","payment_method":"Visa-Mastercard","transactionHashKey":"118bf75ac6aa8aca9ac7ac5bb8bfab6fc9b822bfdacec64d2e8aab885f0d7be6"}');
        $this->assertSame('kd7rwmxqoltbv3ezsa', $paid->intentKey());

        $cancel = $this->verify(WebhookType::Cancel, '{"referenceId":4266311,"paymentMethod":"Aman","transactionKey":"kd7rwmxqoltbv3ezsa","hashKey":"2b2556b9e0f5674e89d97f61d71272a155b198ac708bbf5b10474991ab567fca"}');
        $this->assertSame('kd7rwmxqoltbv3ezsa', $cancel->hint('transactionKey'));

        foreach (['AbCd1234EfGh', 'kd7rwmxqoltbv3ezsa'] as $key) {
            $this->assertSame($key, $this->verify(WebhookType::Failed, SignedWebhook::failed($key)->signedWith('test-vendor-key')->toJson())->intentKey());
        }
    }

    public function test_a_refund_amount_written_differently_still_matches(): void
    {
        // Signed as "150.50" (golden) but sent as the JSON number 150.5.
        $this->verify(WebhookType::Refund, '{"transactionId":12345,"amount":150.5,"currency":"EGP","hashKey":"f6f78ba5557f2a006e53f0af012c1b83dc62e29754794e1923e1a0443d181ae0"}');
        $this->addToAssertionCount(1);
    }

    public function test_the_paid_webhook_falls_back_to_hash_key(): void
    {
        $this->verify(WebhookType::Paid, '{"transaction_id":12345,"transaction_key":"'.self::INTENT.'","payment_method":"Visa-Mastercard","hashKey":"7cdea7eb0dbbe574bfa2d3acbdca0f657735fdbf7ce62e903fa734780cb2ce5f"}');
        $this->addToAssertionCount(1);
    }

    public function test_unsigned_fields_can_be_edited_which_is_why_they_are_never_trusted(): void
    {
        $body = '{"transaction_id":12345,"transaction_key":"'.self::INTENT.'","payment_method":"Visa-Mastercard","status":"%s","paidAmount":%s,"transactionHashKey":"7cdea7eb0dbbe574bfa2d3acbdca0f657735fdbf7ce62e903fa734780cb2ce5f"}';

        $this->verify(WebhookType::Paid, sprintf($body, 'pending', '1'));
        $this->verify(WebhookType::Paid, sprintf($body, 'paid', '999999'));
        $this->addToAssertionCount(1);
    }

    public function test_edited_signed_fields_or_another_key_are_refused(): void
    {
        $valid = SignedWebhook::paid(self::INTENT, 12345)->signedWith('test-vendor-key')->toArray();

        $cases = [
            'edited transaction id' => ['transaction_id' => 12346] + $valid,
            'edited intent key' => ['transaction_key' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8'] + $valid,
            'edited method' => ['payment_method' => 'Fawry'] + $valid,
            'other key' => SignedWebhook::paid(self::INTENT, 12345)->signedWith('another-key')->toArray(),
        ];

        foreach ($cases as $name => $body) {
            $this->assertOutcome(InvalidWebhookException::BAD_SIGNATURE, WebhookType::Paid, (string) json_encode($body), $name);
        }

        $this->assertOutcome(InvalidWebhookException::MALFORMED, WebhookType::Failed, (string) json_encode($valid), 'a paid body on the failed URL (transactionHashKey is not read there)');
    }

    public function test_missing_or_malformed_signed_fields_are_refused_before_hashing(): void
    {
        $valid = SignedWebhook::paid(self::INTENT, 12345)->signedWith('test-vendor-key')->toArray();

        $cases = [
            'no intent key' => array_diff_key($valid, ['transaction_key' => 1]),
            'intent key too short' => ['transaction_key' => 'abc'] + $valid,
            'intent key too long' => ['transaction_key' => str_repeat('a', 37)] + $valid,
            'intent key with a slash' => ['transaction_key' => 'kd7r/wmxqoltbv3ezsa'] + $valid,
            'intent key with a newline' => ['transaction_key' => self::INTENT."\n"] + $valid,
            'transaction id not digits' => ['transaction_id' => '12a'] + $valid,
            'negative transaction id' => ['transaction_id' => -1] + $valid,
            'transaction id as an object' => ['transaction_id' => ['x' => 1]] + $valid,
            'control character in the method' => ['payment_method' => "Visa\n"] + $valid,
            'method too long' => ['payment_method' => str_repeat('a', 101)] + $valid,
            'no hash' => array_diff_key($valid, ['transactionHashKey' => 1]),
            'short hash' => ['transactionHashKey' => 'abc'] + $valid,
            'hash not hex' => ['transactionHashKey' => str_repeat('z', 64)] + $valid,
        ];

        foreach ($cases as $name => $body) {
            $this->assertOutcome(InvalidWebhookException::MALFORMED, WebhookType::Paid, (string) json_encode($body), $name);
        }
    }

    public function test_the_builder_signs_json_and_form_bodies_for_every_type(): void
    {
        $webhooks = [
            SignedWebhook::paid(self::INTENT),
            SignedWebhook::failed(self::INTENT),
            SignedWebhook::cancel(4266311, 'Aman', self::INTENT),
            SignedWebhook::refund(12345, '150.50'),
        ];

        foreach ($webhooks as $webhook) {
            $webhook = $webhook->signedWith('test-vendor-key');

            $this->assertInstanceOf(VerifiedWebhook::class, $this->verify($webhook->type(), $webhook->toJson()));
            $this->assertInstanceOf(VerifiedWebhook::class, $this->verify($webhook->type(), $webhook->toForm()));
        }
    }

    private function verify(WebhookType $type, string $body): VerifiedWebhook
    {
        $payload = RawPayload::parse($body);
        $this->assertNotNull($payload, 'unparseable body');

        return $this->verifier->verify($type, $payload);
    }

    private function assertOutcome(string $outcome, WebhookType $type, string $body, string $name): void
    {
        try {
            $this->verify($type, $body);
            $this->fail("Accepted: {$name}");
        } catch (InvalidWebhookException $e) {
            $this->assertSame($outcome, $e->outcome, $name);
        }
    }
}

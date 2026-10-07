<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Webhooks\RawPayload;
use PHPUnit\Framework\TestCase;

class RawPayloadTest extends TestCase
{
    public function test_json_numbers_keep_the_text_that_was_sent(): void
    {
        $payload = RawPayload::parse('{"amount":150.50,"transaction_id":12345678901234567890,"small":-0.5e3,"text":"a\"1,2"}');

        $this->assertNotNull($payload);
        $this->assertSame('150.50', $payload->string('amount'));
        $this->assertSame('12345678901234567890', $payload->string('transaction_id'));
        $this->assertSame('-0.5e3', $payload->string('small'));
        $this->assertSame('a"1,2', $payload->string('text'));
    }

    public function test_form_bodies_are_parsed_without_trimming(): void
    {
        $payload = RawPayload::parse('transaction_id=12345&payment_method=Visa-Mastercard&note=+padded+');

        $this->assertSame('12345', $payload?->string('transaction_id'));
        $this->assertSame(' padded ', $payload?->string('note'));
    }

    public function test_only_non_empty_top_level_scalars_are_values(): void
    {
        $payload = RawPayload::parse('{"customerData":{"email":"a@b.test"},"list":[1],"flag":true,"none":null,"empty":"","id":"7"}');

        foreach (['customerData', 'list', 'flag', 'none', 'empty', 'missing'] as $key) {
            $this->assertNull($payload?->string($key), $key);
        }

        $this->assertSame('7', $payload?->string('id'));
        $this->assertTrue($payload?->has('none'));
        $this->assertNull(RawPayload::parse('transaction_id[]=1&x=2')?->string('transaction_id'));
    }

    public function test_anything_that_is_not_an_object_of_fields_is_refused(): void
    {
        $bodies = [
            'empty' => '',
            'json list' => '[1,2]',
            'empty object' => '{}',
            'broken json' => '{"a":',
            'leading zero' => '{"a":012}',
            'bare dot' => '{"a":1.}',
            'too deep' => str_repeat('{"a":', 20).'1'.str_repeat('}', 20),
            'too large' => '{"a":"'.str_repeat('x', RawPayload::MAX_BYTES).'"}',
        ];

        foreach ($bodies as $name => $body) {
            $this->assertNull(RawPayload::parse($body), $name);
        }
    }

    public function test_form_bodies_are_limited_to_200_fields(): void
    {
        $fields = fn (int $count) => implode('&', array_map(fn ($i) => "f{$i}=1", range(1, $count)));

        $this->assertNotNull(RawPayload::parse($fields(200)));
        $this->assertNull(RawPayload::parse($fields(201)));
        $this->assertNull(RawPayload::parse($fields(1500)), 'parse_str would warn past max_input_vars');
        $this->assertNull(RawPayload::parse('a[]='.str_repeat('&a[]=', 300)), 'array fields count too');
    }
}

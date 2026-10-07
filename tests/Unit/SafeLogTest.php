<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Support\SafeLog;
use PHPUnit\Framework\TestCase;

class SafeLogTest extends TestCase
{
    public function test_only_allowed_keys_and_short_clean_scalars_survive(): void
    {
        $safe = (new SafeLog(null))->filter([
            'type' => 'paid',
            'outcome' => 'accepted',
            'status' => PaymentStatus::Paid,
            'http_status' => 200,
            'reason' => "line\nbreak <script> ".str_repeat('x', 100),
            'payment' => ['nested' => 'array'],
            'email' => 'buyer@example.test',
            'api_key' => 'secret',
            'body' => '{"hashKey":"..."}',
            'intent_key' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $this->assertSame(['type', 'outcome', 'status', 'http_status', 'reason'], array_keys($safe));
        $this->assertSame('paid', $safe['status']);
        $this->assertSame(200, $safe['http_status']);
        $this->assertStringNotContainsString("\n", (string) $safe['reason']);
        $this->assertStringNotContainsString('<', (string) $safe['reason']);
        $this->assertLessThanOrEqual(64, strlen((string) $safe['reason']));
    }

    public function test_no_channel_means_no_logging(): void
    {
        (new SafeLog(null))->info('webhook', ['type' => 'paid']);
        $this->addToAssertionCount(1);
    }
}

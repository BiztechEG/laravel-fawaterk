<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Ledger\Fingerprint;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

class FingerprintTest extends TestCase
{
    public function test_the_same_facts_hash_the_same_however_they_are_typed_or_ordered(): void
    {
        $fresh = [
            'plan' => 3,
            'amount' => 150.5,
            'paid' => true,
            'starts' => new DateTimeImmutable('2026-10-01 12:00:00.750', new DateTimeZone('Africa/Cairo')),
            'status' => PaymentStatus::Paid,
            'extras' => ['b' => 2, 'a' => 1],
        ];

        $fromDatabase = [
            'extras' => ['a' => '1', 'b' => '2'],
            'status' => 'paid',
            'starts' => new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('UTC')),
            'paid' => '1',
            'amount' => 150.50,
            'plan' => '3',
        ];

        $this->assertSame(Fingerprint::hash($fresh), Fingerprint::hash($fromDatabase));
        $this->assertSame(
            '{"amount":"150.5","extras":{"a":"1","b":"2"},"paid":"1","plan":"3","starts":"2026-10-01T09:00:00Z","status":"paid"}',
            Fingerprint::canonical($fresh),
        );
    }

    public function test_real_differences_change_the_hash(): void
    {
        $base = Fingerprint::hash(['plan' => 3, 'user' => 7]);

        $this->assertNotSame($base, Fingerprint::hash(['plan' => 3, 'user' => 8]));
        $this->assertNotSame($base, Fingerprint::hash(['plan' => 3]));
        $this->assertNotSame(Fingerprint::hash(['a' => [1, 2]]), Fingerprint::hash(['a' => [2, 1]]), 'list order matters');
        $this->assertNotSame(Fingerprint::hash(['a' => ['x']]), Fingerprint::hash(['a' => ['0' => 'x', '1' => 'y']]));
        $this->assertNotSame(Fingerprint::hash(['a' => null]), Fingerprint::hash(['a' => '']));
    }

    public function test_values_that_cannot_be_compared_reliably_are_refused(): void
    {
        foreach ([new stdClass, INF, fopen('php://memory', 'r')] as $value) {
            try {
                Fingerprint::hash(['x' => $value]);
                $this->fail('Accepted '.get_debug_type($value));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

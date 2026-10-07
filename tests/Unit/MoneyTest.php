<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Data\Money;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_own_amounts_are_converted_to_minor_units(): void
    {
        $this->assertSame(15000, Money::toMinor('150'));
        $this->assertSame(15000, Money::toMinor(150));
        $this->assertSame(15050, Money::toMinor('150.5'));
        $this->assertSame(15025, Money::toMinor('150.25'));
        $this->assertSame(15050, Money::toMinor('150.500'));
        $this->assertSame(0, Money::toMinor('0'));
    }

    public function test_own_amounts_are_strict(): void
    {
        foreach (['150.255', '-1', '1,000', '1e3', ' ', 'abc', '150.'] as $bad) {
            try {
                Money::toMinor($bad);
                $this->fail("[{$bad}] should have been rejected.");
            } catch (InvalidRequestException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidRequestException::class);
        Money::toMinor(-5);
    }

    public function test_api_numbers_are_parsed_without_float_comparisons(): void
    {
        $this->assertSame(15000, Money::fromApiNumber(150));
        $this->assertSame(15050, Money::fromApiNumber(150.5));
        $this->assertSame(30, Money::fromApiNumber(0.1 + 0.2));
        $this->assertSame(10000, Money::fromApiNumber('100.00'));
        $this->assertSame(250, Money::fromApiNumber(2.5));
    }

    public function test_api_numbers_with_more_than_two_decimals_or_nonsense_are_rejected(): void
    {
        foreach ([150.005, -5, '-5', null, 'abc', [], INF, NAN, true, PHP_INT_MAX, 92233720368547759, 1000000000000000, '1000000000000000', 1.0e16] as $bad) {
            try {
                Money::fromApiNumber($bad);
                $this->fail('A bad API amount was accepted: '.var_export($bad, true));
            } catch (UnexpectedResponseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_minor_units_are_sent_as_json_numbers_and_formatted(): void
    {
        $this->assertSame(150, Money::toApiNumber(15000));
        $this->assertSame(150.5, Money::toApiNumber(15050));
        $this->assertSame('150.00', Money::format(15000));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('1019.15', Money::format(101915));
        $this->assertSame('-0.05', Money::format(-5));
        $this->assertSame('-150.25', Money::format(-15025));
    }
}

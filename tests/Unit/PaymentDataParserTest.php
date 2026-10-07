<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Data\PaymentData\PaymentDataParser;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentLink;
use BiztechEG\Fawaterk\Data\PaymentData\ReferenceCode;
use BiztechEG\Fawaterk\Data\PaymentData\WalletRequest;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class PaymentDataParserTest extends TestCase
{
    private PaymentDataParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PaymentDataParser(new DateTimeZone('Africa/Cairo'));
    }

    public function test_a_hosted_link_comes_from_data_url(): void
    {
        $data = $this->parser->parse(['url' => 'https://app.fawaterk.com/ts/a1b2c']);

        $this->assertInstanceOf(PaymentLink::class, $data);
        $this->assertSame('https://app.fawaterk.com/ts/a1b2c', $data->url);
    }

    public function test_a_card_link_comes_from_redirect_to_or_redirect_url(): void
    {
        $this->assertSame('https://staging.fawaterk.com/link/I0PAH', $this->parser->parse(['payment_data' => ['redirectTo' => 'https://staging.fawaterk.com/link/I0PAH']])->url);
        $this->assertSame('https://bank.test/3ds', $this->parser->parse(['payment_data' => ['redirect_url' => 'https://bank.test/3ds']])->url);
    }

    public function test_a_reference_code_is_read_in_the_provider_timezone(): void
    {
        $data = $this->parser->parse(['payment_data' => ['referenceNumber' => '981335305', 'expireDate' => '2026-10-01 15:53:41']]);

        $this->assertInstanceOf(ReferenceCode::class, $data);
        $this->assertSame('981335305', $data->referenceNumber);
        $this->assertSame('2026-10-01T15:53:41+03:00', $data->expiresAt?->format(DATE_ATOM));
    }

    public function test_the_expiry_format_of_the_live_api_is_read(): void
    {
        // The staging API's answer for a Fawry code.
        $data = $this->parser->parse(['payment_data' => [
            'referenceNumber' => '712345678', 'expirationTime' => '08 Oct 2026, 04:42 PM', 'expireDate' => '08 Oct 2026, 04:42 PM',
            'fawryCode' => '712345678', 'reference' => 'TR-1001',
        ]]);

        $this->assertInstanceOf(ReferenceCode::class, $data);
        $this->assertSame('2026-10-08T16:42:00+03:00', $data->expiresAt?->format(DATE_ATOM));

        foreach (['08 Oct 2026, 12:05 AM' => '2026-10-08T00:05:00+03:00', '31 Dec 2026, 11:59 PM' => '2026-12-31T23:59:00+02:00'] as $expiry => $expected) {
            $this->assertSame($expected, $this->parser->parse(['payment_data' => ['referenceNumber' => '1', 'expireDate' => $expiry]])->expiresAt?->format(DATE_ATOM));
        }

        foreach (['31 Feb 2026, 04:42 PM', '08 Oct 2026, 13:42 PM', '8 Oct 2026, 04:42 PM'] as $impossible) {
            $this->assertNull($this->parser->parse(['payment_data' => ['referenceNumber' => '1', 'expireDate' => $impossible]])->expiresAt, $impossible);
        }
    }

    public function test_a_numeric_reference_and_an_unreadable_expiry_are_handled(): void
    {
        $data = $this->parser->parse(['payment_data' => ['referenceNumber' => 981335305, 'expirationTime' => 'next week']]);

        $this->assertInstanceOf(ReferenceCode::class, $data);
        $this->assertSame('981335305', $data->referenceNumber);
        $this->assertNull($data->expiresAt, 'an unreadable expiry is unknown, never guessed');
    }

    public function test_an_impossible_expiry_is_unknown_not_rolled_over(): void
    {
        foreach (['2026-02-30 12:00:00', '2026-10-01 25:00:00', '2026-13-01 00:00:00'] as $expiry) {
            $data = $this->parser->parse(['payment_data' => ['referenceNumber' => '981335305', 'expireDate' => $expiry]]);

            $this->assertNull($data->expiresAt, $expiry);
        }
    }

    public function test_an_empty_payment_data_falls_back_to_the_link(): void
    {
        foreach ([[], null] as $empty) {
            $data = $this->parser->parse(['url' => 'https://app.fawaterk.com/ts/a1b2c', 'payment_data' => $empty]);

            $this->assertInstanceOf(PaymentLink::class, $data);
        }
    }

    public function test_a_wallet_request_is_recognised(): void
    {
        $data = $this->parser->parse(['payment_data' => ['systemReference' => '4266311', 'isoQr' => '0002010102']]);

        $this->assertInstanceOf(WalletRequest::class, $data);
        $this->assertSame('4266311', $data->systemReference);
    }

    public function test_unknown_or_unsafe_shapes_fail_closed(): void
    {
        $cases = [
            'no url and no payment data' => ['intent_key' => 'x'],
            'unknown payment data' => ['payment_data' => ['something' => 'else']],
            'http link' => ['url' => 'http://app.fawaterk.com/ts/a1'],
            'javascript link' => ['payment_data' => ['redirectTo' => 'javascript:alert(1)']],
            'malformed reference' => ['payment_data' => ['referenceNumber' => '98 13<script>']],
            'reference with a trailing newline' => ['payment_data' => ['referenceNumber' => "981335305\n"]],
        ];

        foreach ($cases as $name => $data) {
            try {
                $this->parser->parse($data);
                $this->fail("Accepted: {$name}");
            } catch (UnexpectedResponseException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

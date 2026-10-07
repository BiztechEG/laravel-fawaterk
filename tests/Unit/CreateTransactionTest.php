<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Data\CartItem;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\Customer;
use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class CreateTransactionTest extends TestCase
{
    public function test_the_payload_matches_fawaterk_field_names_and_types(): void
    {
        $request = new CreateTransaction(
            cartTotalMinor: 30050,
            customer: new Customer('Ahmed', 'Ali', 'ahmed@example.com', '01000000000'),
            cartItems: [new CartItem('Order #1001', 15025, 2)],
            redirectionUrls: new RedirectionUrls(successUrl: 'https://shop.test/ok', webhookUrl: 'https://shop.test/hooks/paid_json'),
            payLoad: ['order' => 1001],
            dueDate: new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Cairo')),
            lang: 'ar',
            paymentMethodId: 3,
            redirectOption: false,
        );

        $payload = $request->toPayload();

        $this->assertSame(300.5, $payload['cartTotal']);
        $this->assertSame('EGP', $payload['currency']);
        $this->assertSame(['first_name' => 'Ahmed', 'last_name' => 'Ali', 'email' => 'ahmed@example.com', 'phone' => '01000000000'], $payload['customer']);
        $this->assertSame([['name' => 'Order #1001', 'price' => 150.25, 'quantity' => 2]], $payload['cartItems']);
        $this->assertSame(['successUrl' => 'https://shop.test/ok', 'webhookUrl' => 'https://shop.test/hooks/paid_json'], $payload['redirectionUrls']);
        $this->assertSame(['order' => 1001], $payload['pay_load']);
        $this->assertSame('2026-10-01T12:00:00+03:00', $payload['due_date']);
        $this->assertSame(3, $payload['payment_method_id']);
        $this->assertFalse($payload['redirectOption']);
        $this->assertFalse($payload['sendEmail'], 'sendEmail is sent explicitly');
        $this->assertFalse($payload['sendSMS'], 'sendSMS is sent explicitly');
        $this->assertArrayNotHasKey('taxData', $payload);
        $this->assertArrayNotHasKey('discountData', $payload);
    }

    public function test_optional_fields_are_left_out_when_not_set(): void
    {
        $payload = $this->valid()->toPayload();

        foreach (['redirectionUrls', 'pay_load', 'due_date', 'lang', 'payment_method_id', 'redirectOption', 'mobileWalletNumber', 'list_style'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }

    public function test_invalid_requests_are_refused_before_anything_is_sent(): void
    {
        $item = new CartItem('Item', 10000);
        $customer = new Customer('Ahmed', 'Ali');

        $cases = [
            'total differs from the items' => fn () => new CreateTransaction(15000, $customer, [$item]),
            'currency other than EGP' => fn () => new CreateTransaction(10000, $customer, [$item], currency: 'USD'),
            'no items' => fn () => new CreateTransaction(10000, $customer, []),
            'zero total' => fn () => new CreateTransaction(0, $customer, [$item]),
            'unknown lang' => fn () => new CreateTransaction(10000, $customer, [$item], lang: 'fr'),
            'unknown list style' => fn () => new CreateTransaction(10000, $customer, [$item], listStyle: 'x'),
            'bad wallet number' => fn () => new CreateTransaction(10000, $customer, [$item], mobileWalletNumber: '01-000'),
            'pay_load too large' => fn () => new CreateTransaction(10000, $customer, [$item], payLoad: ['x' => str_repeat('a', 3000)]),
            'http redirect url' => fn () => new RedirectionUrls(successUrl: 'http://shop.test/ok'),
            'url with credentials' => fn () => new RedirectionUrls(webhookUrl: 'https://user:pass@shop.test/hook'),
            'relative url' => fn () => new RedirectionUrls(failUrl: '/fail'),
            'empty first name' => fn () => new Customer(' ', 'Ali'),
            'invalid email' => fn () => new Customer('Ahmed', 'Ali', 'not-an-email'),
            'invalid phone' => fn () => new Customer('Ahmed', 'Ali', null, '010-000'),
            'phone with a trailing newline' => fn () => new Customer('Ahmed', 'Ali', null, "01000000000\n"),
            'wallet with a trailing newline' => fn () => new CreateTransaction(10000, $customer, [$item], mobileWalletNumber: "01000000000\n"),
            'free item' => fn () => new CartItem('Item', 0),
            'zero quantity' => fn () => new CartItem('Item', 100, 0),
        ];

        foreach ($cases as $name => $build) {
            $this->assertRefused($name, $build);
        }
    }

    private function valid(): CreateTransaction
    {
        return new CreateTransaction(10000, new Customer('Ahmed', 'Ali'), [new CartItem('Item', 10000)]);
    }

    private function assertRefused(string $name, Closure $build): void
    {
        try {
            $build();
            $this->fail("Not refused: {$name}");
        } catch (InvalidRequestException) {
            $this->addToAssertionCount(1);
        }
    }
}

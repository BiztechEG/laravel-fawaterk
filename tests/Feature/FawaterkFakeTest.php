<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\CartItem;
use BiztechEG\Fawaterk\Data\CreateTransaction;
use BiztechEG\Fawaterk\Data\Customer;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentLink;
use BiztechEG\Fawaterk\Data\PaymentData\ReferenceCode;
use BiztechEG\Fawaterk\Data\PaymentData\WalletRequest;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Exceptions\TransactionNotFoundException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;

class FawaterkFakeTest extends TestCase
{
    public function test_the_fake_replaces_the_client_everywhere_and_needs_no_credentials(): void
    {
        $fake = Fawaterk::fake()->setPaymentMethods(new PaymentMethod(3, 'Fawry', null, false, true));
        config()->set('fawaterk.methods', ['fawry' => ['name_en' => 'Fawry']]);

        $this->assertSame($fake, Fawaterk::client());
        $this->assertSame($fake, $this->app->make(FawaterkClient::class));
        $this->assertSame(3, Fawaterk::methods()->resolve('fawry')->id);
    }

    public function test_the_fake_is_refused_on_live_and_in_production(): void
    {
        $real = $this->app->make(FawaterkClient::class);

        config()->set('fawaterk.environment', 'live');
        $this->assertRaises(fn () => Fawaterk::fake(), LogicException::class, 'FAWATERK_ENV=live');

        config()->set('fawaterk.environment', 'staging');
        $this->app['env'] = 'production';
        $this->assertRaises(fn () => Fawaterk::fake(), LogicException::class, 'APP_ENV=production');

        $this->assertSame($real, $this->app->make(FawaterkClient::class), 'the real client stays');

        $this->app['env'] = 'testing';
        $this->assertInstanceOf(FawaterkFake::class, Fawaterk::fake());
    }

    public function test_the_fake_method_list_is_never_stale_and_never_reaches_the_real_cache(): void
    {
        config()->set('fawaterk.methods', ['fawry' => ['name_en' => 'Fawry']]);

        $fake = Fawaterk::fake()->setPaymentMethods(new PaymentMethod(3, 'Fawry', null, false, true));
        $this->assertSame(3, Fawaterk::methods()->resolve('fawry')->id);

        $fake->setPaymentMethods(new PaymentMethod(30, 'Fawry', null, false, true));
        $this->assertSame(30, Fawaterk::methods()->resolve('fawry')->id);

        $realKey = $this->app->make(AccountRepository::class)->get()->cachePrefix().':methods';
        $this->assertNull(Cache::get($realKey));
    }

    public function test_a_hosted_link_or_a_reference_code_is_returned(): void
    {
        $fake = Fawaterk::fake();

        $link = $fake->createTransaction($this->request());
        $code = $fake->createTransaction($this->request(paymentMethodId: 3, redirectOption: false));

        $this->assertInstanceOf(PaymentLink::class, $link->paymentData);
        $this->assertStringStartsWith('https://fawaterk.test/ts/', $link->paymentData->url);
        $this->assertInstanceOf(ReferenceCode::class, $code->paymentData);
        $this->assertMatchesRegularExpression('/^\d{9}$/', $code->paymentData->referenceNumber);
    }

    public function test_intents_start_unpaid_and_can_be_marked_paid(): void
    {
        $fake = Fawaterk::fake();
        $intent = $fake->createTransaction($this->request());

        $before = $fake->getTransaction($intent->intentKey);
        $this->assertFalse($before->paid);
        $this->assertSame(10000, $before->totalMinor);
        $this->assertSame(0, $before->transactionId);

        // The fake's keys are short opaque ones, like the API's: they keep their case (only a UUID's case is free).
        $this->assertMatchesRegularExpression('/^[a-z0-9]{18}$/', $intent->intentKey);
        $fake->markPaid($intent->intentKey, transactionId: 99, commissionMinor: 250);

        $after = $fake->getTransaction($intent->intentKey);
        $this->assertTrue($after->paid);
        $this->assertSame(99, $after->transactionId);
        $this->assertSame(10000, $after->totalMinor);
        $this->assertSame(250, $after->commissionMinor);
        $this->assertSame('Visa-Mastercard', $after->paymentMethod);
    }

    public function test_unknown_intents_are_not_found(): void
    {
        $this->assertRaises(fn () => Fawaterk::fake()->getTransaction('550e8400-e29b-41d4-a716-446655440000'), TransactionNotFoundException::class);
    }

    public function test_custom_answers_refund_pages_and_assertions(): void
    {
        $fake = Fawaterk::fake()
            ->createTransactionUsing(fn () => new TransactionIntent('550e8400-e29b-41d4-a716-446655440000', new WalletRequest('4266311', null)))
            ->setRefundPage(new RefundPage(1, 1, [new RefundItem(7, '3', 99, 5000, 'approved')]));

        $fake->assertNothingCreated();

        $intent = $fake->createTransaction($this->request());

        $this->assertInstanceOf(WalletRequest::class, $intent->paymentData);
        $this->assertFalse($fake->getTransaction($intent->intentKey)->paid);
        $this->assertSame(99, $fake->refundPage()->items[0]->refundableId);
        $this->assertSame([], $fake->refundPage(2)->items);

        $fake->assertCreatedCount(1);
        $fake->assertTransactionCreated(fn (CreateTransaction $request) => $request->cartTotalMinor === 10000);

        $this->assertAssertionFails(fn () => $fake->assertNothingCreated());
        $this->assertAssertionFails(fn () => $fake->assertCreatedCount(2));
        $this->assertAssertionFails(fn () => $fake->assertTransactionCreated(fn (CreateTransaction $request) => $request->cartTotalMinor === 1));
    }

    private function assertAssertionFails(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The fake assertion passed but should have failed.');
    }

    private function request(?int $paymentMethodId = null, ?bool $redirectOption = null): CreateTransaction
    {
        return new CreateTransaction(
            10000,
            new Customer('Ahmed', 'Ali'),
            [new CartItem('Order #1', 10000)],
            paymentMethodId: $paymentMethodId,
            redirectOption: $redirectOption,
        );
    }
}

<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentRefunded;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Results\ResultUrls;
use BiztechEG\Fawaterk\Support\SafeLog;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\Fixtures\KeyedOrder;
use BiztechEG\Fawaterk\Tests\Fixtures\UlidOrder;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use BiztechEG\Fawaterk\Webhooks\WebhookType;
use BiztechEG\Fawaterk\Webhooks\WebhookUrls;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Hardening cases that no other test covers.
 */
class HardeningTest extends LedgerTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Relation::requireMorphMap(false);
        Relation::morphMap([], false);

        parent::tearDown();
    }

    public function test_a_replayed_refund_with_edited_unsigned_fields_is_counted_once(): void
    {
        Event::fake([PaymentRefunded::class]);
        $payment = $this->paidPayment(555);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(10, '3', 555, 5000, 'approved')]));

        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        foreach ([['approvedAt' => '2026-10-01 10:00:00'], ['status' => 2, 'reason' => 'edited'], ['refundId' => 99]] as $unsigned) {
            Carbon::setTestNow(Carbon::now()->addMinutes(2));
            $this->send(SignedWebhook::refund(555, '50.00')->with($unsigned))->assertOk();
        }

        $this->assertSame(5000, $payment->refresh()->refunded_amount_minor);
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fulfilmentModes(): iterable
    {
        yield 'manual' => ['manual'];
        yield 'after_listeners' => ['after_listeners'];
    }

    /**
     * @dataProvider fulfilmentModes
     */
    #[DataProvider('fulfilmentModes')]
    public function test_a_late_payment_whose_listener_fails_is_sent_again_until_delivered(string $mode): void
    {
        config()->set('fawaterk.fulfilment', $mode);
        $payment = $this->started();
        $payment->forceFill(['status' => PaymentStatus::Expired])->save();

        $calls = [];
        $failing = true;
        Event::listen(PaymentPaid::class, function (PaymentPaid $event) use (&$calls, &$failing) {
            $calls[] = $event->late;

            if ($failing) {
                throw new RuntimeException('SMTP down');
            }

            if (config('fawaterk.fulfilment') === 'manual') {
                $event->payment->markFulfilled();
            }
        });

        $this->fake->markPaid((string) $payment->intent_key, transactionId: 777);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 777))->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertTrue($payment->hasFlag(Flag::LatePayment), 'informational');
        $this->assertNull($payment->fulfilled_at);

        $failing = false;
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->app->make(Reconciler::class)->run();
        Carbon::setTestNow(Carbon::now()->addHours(5));
        $this->app->make(Reconciler::class)->run();

        $this->assertNotNull($payment->refresh()->fulfilled_at);
        $this->assertSame([true, true], $calls, 'sent again once, still marked late; nothing after delivery');
    }

    /**
     * @return iterable<string, array{class-string<KeyedOrder>, string}>
     */
    public static function stringKeys(): iterable
    {
        yield 'uuid' => [KeyedOrder::class, 'uuid'];
        yield 'ulid' => [UlidOrder::class, 'ulid'];
    }

    /**
     * @param  class-string<KeyedOrder>  $class
     *
     * @dataProvider stringKeys
     */
    #[DataProvider('stringKeys')]
    public function test_string_keyed_payables_pay_end_to_end_under_an_enforced_morph_map(string $class, string $keyType): void
    {
        $this->remigrate($keyType);
        Schema::create('keyed_orders', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->unsignedBigInteger('total_minor');
            $table->timestamps();
        });
        Relation::enforceMorphMap(['keyed-order' => $class]);
        Event::fake([PaymentPaid::class]);

        $order = $class::query()->create(['total_minor' => 25000]);
        $other = $class::query()->create(['total_minor' => 25000]);
        $result = Fawaterk::checkout($order);
        Fawaterk::checkout($other);

        $payment = $this->payment($result->paymentUuid);
        $this->assertSame('keyed-order', $payment->payable_type);
        $this->assertSame($order->id, $payment->payable_id);

        $this->fake->markPaid((string) $payment->intent_key, transactionId: 901);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 901))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertFalse($payment->hasBlockingFlag());
        Event::assertDispatched(PaymentPaid::class, fn (PaymentPaid $event) => $event->payment->payable?->getKey() === $order->id);
        $this->assertSame(1, $order->fawaterkPayments()->count());
        $this->assertTrue($other->fawaterkPayments()->first()?->status === PaymentStatus::Created, 'the other order is untouched');
    }

    public function test_nothing_sensitive_reaches_any_log_across_the_whole_flow(): void
    {
        $records = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$records) {
            $records[] = [$log->message, $log->context];
        });
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $this->app->instance(SafeLog::class, new SafeLog($logger));
        $this->useCredentials();
        $this->app['router']->fawaterk();

        $payment = $this->started();
        $this->send(SignedWebhook::paid((string) $payment->intent_key)->with(['api_key' => 'leaked-api-key', 'customerData' => ['email' => 'buyer@example.test']]));
        $this->send(SignedWebhook::paid((string) $payment->intent_key)->signedWith('wrong-key'))->assertStatus(401);
        $this->fake->markPaid((string) $payment->intent_key);
        $this->get(substr((string) Fawaterk::resultUrl($payment), strlen('https://shop.test')))->assertOk();
        $this->app->make(Reconciler::class)->run();
        Artisan::call('fawaterk:doctor', ['--offline' => true]);

        $dump = (string) json_encode([$records, $logger->records]);
        $this->assertNotSame('[[],[]]', $dump);

        foreach (['customer@example.test', 'buyer@example.test', 'Test Customer', 'leaked-api-key', 'test-vendor-key', 'test-client-secret', (string) $payment->intent_key, (string) $payment->checkout_url] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_the_config_can_be_cached(): void
    {
        $config = (array) config('fawaterk');

        $this->assertNoObjects($config);
        $this->assertSame($config, eval('return '.var_export($config, true).';'));
    }

    public function test_the_routes_can_be_cached_and_still_work(): void
    {
        $router = $this->app['router'];
        $router->setRoutes(new RouteCollection);
        $router->name('shop.')->group(function () use ($router) {
            $router->fawaterkWebhooks('hooks/fawaterk');
            $router->fawaterk('pay');
        });

        foreach ($router->getRoutes() as $route) {
            $route->prepareForSerialization();
            serialize($route);
        }

        $router->setCompiledRoutes($router->getRoutes()->compile());
        $payment = $this->started();

        $this->assertSame('https://shop.test/hooks/fawaterk/paid_json', $this->app->make(WebhookUrls::class)->for(WebhookType::Paid));
        $url = (string) $this->app->make(ResultUrls::class)->for($payment->uuid);
        $this->assertStringStartsWith('https://shop.test/pay/result/'.$payment->uuid.'/', $url);

        $this->get(substr($url, strlen('https://shop.test')))->assertOk();
        $this->fake->markPaid((string) $payment->intent_key);
        $this->call('POST', '/hooks/fawaterk/paid_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], SignedWebhook::paid((string) $payment->intent_key)->toJson())->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    private function assertNoObjects(mixed $value): void
    {
        if (is_array($value)) {
            array_walk($value, fn ($item) => $this->assertNoObjects($item));

            return;
        }

        $this->assertFalse(is_object($value) || $value instanceof Closure, 'config holds only plain values');
    }

    private function remigrate(string $keyType): void
    {
        Schema::drop(Ledger::table('webhook_events'));
        Schema::drop(Ledger::table('payments'));
        config()->set('fawaterk.ledger.payable_key_type', $keyType);
        (include __DIR__.'/../../database/migrations/create_fawaterk_tables.php.stub')->up();
    }

    private function started(): FawaterkPayment
    {
        return $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
    }

    private function paidPayment(int $transactionId): FawaterkPayment
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, transactionId: $transactionId);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, $transactionId))->assertOk();

        return $payment->refresh();
    }

    private function send(SignedWebhook $webhook): TestResponse
    {
        return $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
    }
}

<?php

namespace BiztechEG\Fawaterk\Tests;

use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Tests\Fixtures\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An app with the ledger migrated (in-memory SQLite), a generic Order model,
 * the webhook route registered and Fawaterk replaced by the fake.
 */
abstract class LedgerTestCase extends TestCase
{
    protected FawaterkFake $fake;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.url', 'https://shop.test');
        $app['config']->set('fawaterk.vendor_api_key', 'test-vendor-key');
        $app['config']->set('fawaterk.methods', [
            'fawry' => ['id' => ['staging' => 3, 'live' => 12]],
            'card' => ['name_en' => 'Visa-Mastercard'],
        ]);
        $app['config']->set('fawaterk.profiles', [
            'hosted' => ['kind' => 'hosted'],
            'fawry' => ['kind' => 'method', 'method' => 'fawry', 'due_after' => 2880],
            'card' => ['kind' => 'method', 'method' => 'card'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        (include __DIR__.'/../database/migrations/create_fawaterk_tables.php.stub')->up();

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('new');
            $table->timestamps();
        });

        $this->app['router']->fawaterkWebhooks();
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->fake = Fawaterk::fake()->setPaymentMethods(
            new PaymentMethod(2, 'Visa-Mastercard', 'فيزا', true, false),
            new PaymentMethod(3, 'Fawry', 'فوري', false, true),
        );
    }

    protected function tearDown(): void
    {
        Ledger::usePaymentModel(FawaterkPayment::class);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function order(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'number' => 'A-'.random_int(1000, 9999),
            'total_minor' => 15000,
            'user_id' => 7,
        ], $attributes));
    }

    protected function payment(string $uuid): FawaterkPayment
    {
        return Ledger::newPayment()->newQuery()->where('uuid', $uuid)->firstOrFail();
    }
}

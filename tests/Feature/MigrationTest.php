<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\FawaterkServiceProvider;
use BiztechEG\Fawaterk\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class MigrationTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    public function test_every_payable_key_type_and_a_custom_prefix_migrate_up_and_down(): void
    {
        config()->set('fawaterk.ledger.table_prefix', 'pay_');

        foreach (['int', 'uuid', 'ulid', 'string'] as $type) {
            config()->set('fawaterk.ledger.payable_key_type', $type);
            $migration = include __DIR__.'/../../database/migrations/create_fawaterk_tables.php.stub';

            $migration->up();
            $this->assertTrue(Schema::hasColumns('pay_payments', ['uuid', 'payable_id', 'purpose', 'intent_key', 'order_fingerprint', 'flags']), $type);
            $this->assertTrue(Schema::hasTable('pay_webhook_events'), $type);

            $migration->down();
            $this->assertFalse(Schema::hasTable('pay_payments'), $type);
        }
    }

    public function test_an_unknown_key_type_is_refused(): void
    {
        config()->set('fawaterk.ledger.payable_key_type', 'bigint');

        $this->assertRaises(fn () => (include __DIR__.'/../../database/migrations/create_fawaterk_tables.php.stub')->up(), InvalidArgumentException::class);
    }

    public function test_the_migration_is_publishable(): void
    {
        $this->assertNotEmpty(ServiceProvider::pathsToPublish(FawaterkServiceProvider::class, 'fawaterk-migrations'));
    }
}

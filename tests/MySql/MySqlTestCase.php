<?php

namespace BiztechEG\Fawaterk\Tests\MySql;

use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Fingerprint;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Tests\Fixtures\Order;
use BiztechEG\Fawaterk\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A test against a real MySQL or MariaDB server, whose worker processes
 * (worker.php) share its database. See MySqlEnvironment for how to run it.
 */
abstract class MySqlTestCase extends TestCase
{
    protected FawaterkFake $fake;

    protected function setUp(): void
    {
        if (! MySqlEnvironment::configured()) {
            $this->markTestSkipped('Set FAWATERK_TEST_MYSQL_DATABASE (a *_testing database) to run the MySQL lock tests.');
        }

        parent::setUp();

        $this->freshSchema();
        $this->app['router']->fawaterkWebhooks();
        $this->fake = Fawaterk::fake()->setPaymentMethods(
            new PaymentMethod(2, 'Visa-Mastercard', null, true, false),
            new PaymentMethod(3, 'Fawry', null, false, true),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        MySqlEnvironment::apply($app);
    }

    private function freshSchema(): void
    {
        $schema = Schema::connection(MySqlEnvironment::CONNECTION);
        $tables = [Ledger::table('webhook_events'), Ledger::table('payments'), 'orders', 'typed_orders', 'cache', 'cache_locks', 'jobs', Barrier::TABLE];

        foreach (['fwi_', 'fwu_', 'fws_'] as $prefix) {
            array_push($tables, $prefix.'webhook_events', $prefix.'payments');
        }

        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }

        (include __DIR__.'/../../database/migrations/create_fawaterk_tables.php.stub')->up();

        $schema->create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('new');
            $table->unsignedInteger('deliveries')->default(0);
            $table->timestamps();
        });
        $schema->create('typed_orders', function (Blueprint $table) {
            $table->id();
            $table->decimal('total', 10, 2);
            $table->dateTime('placed_at');
            $table->string('kind', 16);
            $table->timestamps();
        });
        $schema->create('cache', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $schema->create('cache_locks', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->string('owner', 191);
            $table->integer('expiration');
        });
        $schema->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue', 191)->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create(Barrier::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->index();
            $table->unsignedInteger('pid');
        });
    }

    protected function order(): Order
    {
        return Order::query()->create(['number' => 'A-'.Str::random(6), 'total_minor' => 15000, 'user_id' => 7]);
    }

    /**
     * A ledger row for $order, as a checkout leaves it.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function row(Order $order, array $attributes = []): FawaterkPayment
    {
        $row = Ledger::newPayment();
        $row->forceFill(array_merge([
            'account' => 'default',
            'environment' => 'staging',
            'payable_type' => $order->getMorphClass(),
            'payable_id' => $order->getKey(),
            'purpose' => 'default',
            'profile' => 'hosted',
            'intent_key' => (string) Str::uuid(),
            'amount_minor' => 15000,
            'currency' => 'EGP',
            'status' => PaymentStatus::Created,
            'order_fingerprint' => Fingerprint::of($order->newQuery()->findOrFail($order->getKey())),
            'checkout_url' => 'https://fawaterk.test/ts/'.Str::uuid(),
            'expires_at' => Carbon::now()->addDay(),
            'next_check_at' => Carbon::now()->addMinutes(5),
        ], $attributes))->save();

        return $row->refresh();
    }

    /**
     * Starts a worker process running one of the Scenarios.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    protected function spawn(string $scenario, array $args = []): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/worker.php', $scenario, json_encode($args, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );

        $this->assertIsResource($process, "The {$scenario} worker did not start.");

        return [$process, $pipes, $scenario];
    }

    /**
     * Waits for a worker and returns its exit code, its JSON result and its output.
     *
     * @param  array{0: resource, 1: array<int, resource>, 2: string}  $worker
     * @return array{code: int, result: array<string, mixed>|null, output: string}
     */
    protected function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $lines = array_values(array_filter(array_map('trim', explode("\n", $output))));
        $json = $lines === [] ? null : json_decode(end($lines), true);

        return ['code' => $code, 'result' => is_array($json) ? $json : null, 'output' => $output.$errors];
    }

    /**
     * @param  array{code: int, result: array<string, mixed>|null, output: string}  $finished
     * @return array<string, mixed>
     */
    protected function succeeded(array $finished): array
    {
        $this->assertSame(0, $finished['code'], $finished['output']);
        $this->assertIsArray($finished['result'], $finished['output']);

        return $finished['result'];
    }

    protected function repeatableRead(): bool
    {
        // transaction_isolation on MySQL 8, tx_isolation on MySQL 5.7 and MariaDB.
        foreach (['transaction_isolation', 'tx_isolation'] as $variable) {
            try {
                $row = (array) DB::selectOne("SELECT @@SESSION.{$variable} AS level");

                return ($row['level'] ?? '') === 'REPEATABLE-READ';
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    /**
     * InnoDB's deadlock counter, or null when it cannot be read.
     */
    protected function deadlocks(): ?int
    {
        try {
            // MariaDB keeps it as a status variable.
            $status = (array) DB::selectOne("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'");

            if (isset($status['Value'])) {
                return (int) $status['Value'];
            }

            $row = (array) DB::selectOne("SELECT `COUNT` AS n, `STATUS` AS s FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'");
        } catch (\Throwable) {
            return null;
        }

        return ($row['s'] ?? '') === 'enabled' ? (int) $row['n'] : null;
    }
}

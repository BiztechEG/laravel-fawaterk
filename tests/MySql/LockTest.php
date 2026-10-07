<?php

namespace BiztechEG\Fawaterk\Tests\MySql;

use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Fingerprint;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\MySql\Fixtures\FulfilOnQueue;
use BiztechEG\Fawaterk\Tests\MySql\Fixtures\OrderKind;
use BiztechEG\Fawaterk\Tests\MySql\Fixtures\TypedOrder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * The row-lock tests that need a real MySQL or MariaDB server.
 */
class LockTest extends MySqlTestCase
{
    public function test_1_two_processes_paying_two_rows_of_one_payable_give_one_paid_and_one_paid_twice(): void
    {
        $pairs = [];
        foreach (range(1, 50) as $ignored) {
            $order = $this->order();
            $pairs[] = [$this->row($order), $this->row($order)];
        }

        $a = $this->spawn('payRace', ['rows' => array_map(fn (array $pair) => $pair[0]->id, $pairs)]);
        $b = $this->spawn('payRace', ['rows' => array_map(fn (array $pair) => $pair[1]->id, $pairs)]);
        $events = array_merge($this->succeeded($this->finish($a))['events'], $this->succeeded($this->finish($b))['events']);

        foreach ($pairs as [$first, $second]) {
            $ids = [$first->id, $second->id];
            $count = fn (string $event) => count(array_filter($events, fn (array $seen) => $seen[0] === $event && in_array($seen[1], $ids, true)));

            $this->assertSame(1, $count('PaymentPaid'), 'exactly one PaymentPaid per payable');
            $this->assertSame(1, $count('PaymentPaidTwice'), 'and the other payment is flagged');

            $rows = [$first->refresh(), $second->refresh()];
            $this->assertSame([PaymentStatus::Paid, PaymentStatus::Paid], [$rows[0]->status, $rows[1]->status]);
            $this->assertSame(1, count(array_filter($rows, fn (FawaterkPayment $row) => $row->hasFlag(Flag::PaidTwice))));
        }
    }

    public function test_2_a_checkout_insert_waits_for_a_group_lock_and_never_deadlocks(): void
    {
        $order = $this->order();
        $row = $this->row($order);

        $holder = $this->spawn('holdGroupLock', ['row' => $row->id, 'hold_ms' => 2500]);
        $checkout = $this->spawn('checkoutAfter', ['order' => $order->id, 'after' => 'held']);
        $this->succeeded($this->finish($holder));
        $inserted = $this->succeeded($this->finish($checkout))['result'];

        $this->assertSame(PaymentStatus::Paid, $row->refresh()->status);
        $this->assertSame(2, $order->fawaterkPayments()->count());

        if ($this->repeatableRead()) {
            $this->assertGreaterThan(1.5, $inserted['seconds'], 'the INSERT waited for the gap lock instead of slipping past it');
        }
    }

    public function test_2_the_group_lock_uses_the_payable_index_for_int_uuid_and_string_keys(): void
    {
        foreach (['int' => 'fwi_', 'uuid' => 'fwu_', 'string' => 'fws_'] as $type => $prefix) {
            config()->set('fawaterk.ledger.table_prefix', $prefix);
            config()->set('fawaterk.ledger.payable_key_type', $type);
            (include __DIR__.'/../../database/migrations/create_fawaterk_tables.php.stub')->up();

            $key = fn (int $i) => match ($type) {
                'int' => (string) $i,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $i),
                default => 'key-'.$i,
            };

            $rows = [];
            foreach (range(1, 400) as $i) {
                $rows[] = [
                    'uuid' => (string) Str::uuid(), 'account' => 'default', 'environment' => 'staging',
                    'payable_type' => 'order', 'payable_id' => $key($i), 'purpose' => 'default', 'profile' => 'hosted',
                    'amount_minor' => 15000, 'currency' => 'EGP', 'status' => 'created', 'order_fingerprint' => str_repeat('0', 64),
                    'intent_key' => (string) Str::uuid(), 'refunded_amount_minor' => 0, 'fulfil_attempts' => 0,
                ];
            }
            DB::table($prefix.'payments')->insert($rows);
            DB::statement("ANALYZE TABLE {$prefix}payments");

            $query = null;
            DB::listen(function (QueryExecuted $executed) use (&$query) {
                if ($query === null && str_contains(strtolower($executed->sql), 'for update') && str_contains(strtolower($executed->sql), 'payable_type')) {
                    $query = $executed;
                }
            });

            $payment = Ledger::newPayment()->newQuery()->where('payable_id', $key(200))->firstOrFail();
            app(PaymentRecorder::class)->applyReRead($payment, new TransactionData(
                intentKey: (string) $payment->intent_key, transactionId: 0, paid: false, totalMinor: 15000, currency: 'EGP',
                commissionMinor: null, paymentMethod: null, statusText: 'unpaid', paidAt: null,
            ));

            $this->assertInstanceOf(QueryExecuted::class, $query, 'the group lock query ran');
            $plan = (array) DB::selectOne('EXPLAIN '.$query->sql, $query->bindings);
            $this->assertSame($prefix.'payments_payable_index', $plan['key'] ?? null, "{$type} keys: ".json_encode($plan));
        }
    }

    public function test_3_a_deadlock_inside_the_lock_or_at_commit_sends_paid_once(): void
    {
        $sent = 0;
        Event::listen(PaymentPaid::class, function () use (&$sent) {
            $sent++;
        });

        // Inside locked(): the UPDATE of the ledger row is rolled back by the
        // server once, and Laravel runs the closure again.
        $payment = $this->row($this->order());
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, Ledger::table('payments'))) {
                $armed = false;

                throw new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        $this->fake->markPaid($this->intent($payment));
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertFalse($armed, 'the deadlock was injected');
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(1, $sent);
        $this->assertSame(1, $payment->fulfil_attempts);

        // At commit of the paid transaction (not of the rate limiter's cache
        // transaction, which commits on the same connection first).
        $second = $this->row($this->order());
        $atCommit = true;
        $ledgerWritten = false;
        DB::listen(function (QueryExecuted $query) use (&$ledgerWritten) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, Ledger::table('payments'))) {
                $ledgerWritten = true;
            }
        });
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$atCommit, &$ledgerWritten) {
            $written = $ledgerWritten;
            $ledgerWritten = false;

            if ($atCommit && $written) {
                $atCommit = false;
                $event->connection->getPdo()->rollBack();

                throw new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        $this->fake->markPaid($this->intent($second));
        $this->send(SignedWebhook::paid((string) $second->intent_key))->assertOk();

        $this->assertFalse($atCommit, 'the commit failure was injected');
        $this->assertSame(PaymentStatus::Paid, $second->refresh()->status);
        $this->assertSame(2, $sent);
    }

    public function test_4_a_process_killed_after_commit_is_caught_up_by_reconcile_once(): void
    {
        $paid = $this->row($this->order());
        $mismatch = $this->row($this->order());

        $this->assertSame(9, $this->finish($this->spawn('crashAfterCommit', ['row' => $paid->id, 'total' => 15000]))['code']);
        $this->assertSame(9, $this->finish($this->spawn('crashAfterCommit', ['row' => $mismatch->id, 'total' => 14999]))['code']);

        $paid->refresh();
        $this->assertSame(PaymentStatus::Paid, $paid->status, 'committed before the crash');
        $this->assertNull($paid->fulfilled_at);
        $this->assertNotNull($paid->next_dispatch_at, 'the re-send was scheduled in the same transaction');
        $this->assertNotNull($mismatch->refresh()->next_alert_at, 'the alert marker survived the crash');

        $sent = [];
        Event::listen(PaymentPaid::class, function (PaymentPaid $event) use (&$sent) {
            $sent[] = 'paid:'.$event->payment->id;
            $event->payment->markFulfilled();
        });
        Event::listen(PaymentAmountMismatch::class, function (PaymentAmountMismatch $event) use (&$sent) {
            $sent[] = 'mismatch:'.$event->payment->id;
        });

        foreach ([11, 12, 200] as $minutes) {
            Carbon::setTestNow(Carbon::now()->addMinutes($minutes));
            $this->app->make(Reconciler::class)->run();
        }

        sort($sent);
        $this->assertSame(['mismatch:'.$mismatch->id, 'paid:'.$paid->id], $sent);
        $this->assertNotNull($paid->refresh()->fulfilled_at);
        $this->assertNull($mismatch->refresh()->next_alert_at);
    }

    public function test_5_two_concurrent_checkouts_on_the_database_lock_store_make_one_link(): void
    {
        $second = $this->order();
        $x = $this->spawn('checkoutTogether', ['order' => $second->id]);
        $y = $this->spawn('checkoutTogether', ['order' => $second->id]);
        $rx = $this->succeeded($this->finish($x))['result'];
        $ry = $this->succeeded($this->finish($y))['result'];

        $this->assertSame($rx['uuid'], $ry['uuid'], 'one link');
        $this->assertSame(1, $rx['created'] + $ry['created'], 'one createTransaction');
        $this->assertSame(1, $second->fawaterkPayments()->count());
        $this->assertTrue($rx['reused'] xor $ry['reused']);
    }

    public function test_5_two_concurrent_checkouts_on_the_redis_lock_store_make_one_link(): void
    {
        $socket = @fsockopen('127.0.0.1', 6379, $errno, $error, 1);

        if ($socket === false || ! extension_loaded('redis')) {
            $this->markTestSkipped('No Redis server (or no phpredis) here; the database lock store is tested above. CI runs Redis.');
        }

        fclose($socket);
        $order = $this->order();
        $x = $this->spawn('checkoutTogether', ['order' => $order->id, 'store' => 'redis']);
        $y = $this->spawn('checkoutTogether', ['order' => $order->id, 'store' => 'redis']);
        $rx = $this->succeeded($this->finish($x))['result'];
        $ry = $this->succeeded($this->finish($y))['result'];

        $this->assertSame($rx['uuid'], $ry['uuid'], 'one link');
        $this->assertSame(1, $rx['created'] + $ry['created']);
    }

    public function test_5_two_re_sends_of_one_payment_at_the_same_moment_send_paid_once(): void
    {
        foreach (range(1, 20) as $ignored) {
            $row = $this->row($this->order(), [
                'status' => PaymentStatus::Paid,
                'paid_amount_minor' => 15000,
                'paid_at' => Carbon::now()->subHour(),
                'next_dispatch_at' => Carbon::now()->subMinute(),
                'fulfil_attempts' => 1,
            ]);

            $a = $this->spawn('redispatchTogether', ['row' => $row->id]);
            $b = $this->spawn('redispatchTogether', ['row' => $row->id]);
            $events = array_merge($this->succeeded($this->finish($a))['events'], $this->succeeded($this->finish($b))['events']);

            $this->assertSame([['PaymentPaid', $row->id]], $events, 'the due check under the row lock lets one through');
            DB::table(Barrier::TABLE)->delete();
        }
    }

    public function test_5_a_second_reconcile_during_a_slow_re_send_does_nothing(): void
    {
        $order = $this->order();
        $row = $this->row($order, [
            'status' => PaymentStatus::Paid,
            'paid_amount_minor' => 15000,
            'expected_amount_minor' => 15000,
            'paid_at' => Carbon::now()->subHour(),
            'fawaterk_transaction_id' => 4242,
            'next_dispatch_at' => Carbon::now()->subMinute(),
            'fulfil_attempts' => 1,
        ]);

        $slow = $this->spawn('slowReconcile', ['hold_ms' => 3000]);
        $second = $this->spawn('secondReconcile');
        $first = $this->succeeded($this->finish($slow));
        $other = $this->succeeded($this->finish($second));

        $this->assertStringContainsString('Another fawaterk:reconcile is running.', $other['result']['output']);
        $this->assertSame([['PaymentPaid', $row->id]], $first['events']);
        $this->assertSame([], $other['events']);
    }

    public function test_6_a_queued_listener_gets_the_row_back_and_mysql_loaded_facts_keep_their_fingerprint(): void
    {
        Event::listen(PaymentPaid::class, FulfilOnQueue::class);

        $order = TypedOrder::query()->create([
            'total' => '150.5',
            'placed_at' => Carbon::parse('2026-09-29 10:11:12.345678'),
            'kind' => OrderKind::Rush,
        ]);
        $loaded = TypedOrder::query()->findOrFail($order->id);

        $this->assertSame('150.50', $loaded->total);
        $this->assertSame(Fingerprint::of($order), Fingerprint::of($loaded), 'in-memory and MySQL-loaded facts hash the same');

        $payment = $this->payment(Fawaterk::checkout($loaded)->paymentUuid);
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertFalse($payment->hasFlag(Flag::OrderChanged), 'no false order_changed');
        $this->assertNull($payment->fulfilled_at, 'the listener is queued');
        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertNotNull($payment->refresh()->fulfilled_at);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_7_a_slow_delivery_does_not_block_another_orders_checkout(): void
    {
        $paid = $this->row($this->order(), [
            'status' => PaymentStatus::Paid,
            'paid_amount_minor' => 15000,
            'paid_at' => Carbon::now(),
        ]);
        $other = $this->order();

        $delivery = $this->spawn('slowFulfil', ['row' => $paid->id, 'hold_ms' => 3000]);
        $checkout = $this->spawn('checkoutAfter', ['order' => $other->id, 'after' => 'fulfilling']);
        $ran = $this->succeeded($this->finish($delivery))['result'];
        $timed = $this->succeeded($this->finish($checkout))['result'];

        $this->assertTrue($ran['ran']);
        $this->assertLessThan(1.5, $timed['seconds'], 'not held up by the other payment\'s row lock');
        $this->assertNotNull($paid->refresh()->fulfilled_at);
    }

    public function test_7_group_and_single_row_locks_side_by_side_never_deadlock(): void
    {
        $order = $this->order();
        $rows = [$this->row($order)->id, $this->row($order)->id, $this->row($order)->id];
        $before = $this->deadlocks();

        $group = $this->spawn('groupLoop', ['rows' => $rows, 'times' => 150]);
        $single = $this->spawn('singleLoop', ['rows' => $rows, 'times' => 150]);
        $this->succeeded($this->finish($group));
        $this->succeeded($this->finish($single));

        if ($before === null) {
            $this->markTestIncomplete('Both loops finished, but InnoDB\'s deadlock counter cannot be read here.');
        }

        $this->assertSame($before, $this->deadlocks(), 'InnoDB reported no deadlock (Laravel would have retried it silently)');
    }

    private function payment(string $uuid): FawaterkPayment
    {
        return Ledger::newPayment()->newQuery()->where('uuid', $uuid)->firstOrFail();
    }

    private function intent(FawaterkPayment $payment): string
    {
        // The fake only knows intents it created: teach it this one.
        $this->fake->setTransaction(new TransactionData(
            intentKey: (string) $payment->intent_key, transactionId: 0, paid: false, totalMinor: $payment->amount_minor,
            currency: 'EGP', commissionMinor: null, paymentMethod: null, statusText: 'unpaid', paidAt: null,
        ));

        return (string) $payment->intent_key;
    }

    private function send(SignedWebhook $webhook): TestResponse
    {
        return $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
    }
}

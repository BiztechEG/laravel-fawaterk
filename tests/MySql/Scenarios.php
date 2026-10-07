<?php

namespace BiztechEG\Fawaterk\Tests\MySql;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentLink;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentPaidTwice;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Tests\Fixtures\Order;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * What each worker process does (tests/MySql/worker.php). Every scenario
 * returns plain data; the events the worker saw are added to it.
 */
final class Scenarios
{
    /** @var list<array{0: string, 1: int|null}> */
    private static array $events = [];

    private static FawaterkFake $fake;

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function run(string $name, array $args): array
    {
        if (($args['store'] ?? null) === 'redis') {
            config()->set('fawaterk.cache_store', 'redis');
        }

        self::$fake = Fawaterk::fake()->setPaymentMethods(
            new PaymentMethod(2, 'Visa-Mastercard', null, true, false),
            new PaymentMethod(3, 'Fawry', null, false, true),
        );

        foreach ([PaymentPaid::class, PaymentPaidTwice::class, PaymentAmountMismatch::class] as $class) {
            Event::listen($class, function (object $event) {
                self::$events[] = [class_basename($event), $event->payment->id ?? null];
            });
        }

        $result = self::{$name}($args);

        return ['events' => self::$events, 'result' => $result];
    }

    /**
     * Test 1: pay one row of each pair, in step with the other worker.
     *
     * @param  array{rows: list<int>}  $args
     */
    private static function payRace(array $args): mixed
    {
        foreach ($args['rows'] as $i => $id) {
            $row = self::row($id);
            Barrier::wait("race-{$i}", 2);
            app(PaymentRecorder::class)->applyReRead($row, self::paid($row, 100000 + $id));
        }

        return null;
    }

    /**
     * Test 2: a paid re-read that holds its group lock for a while.
     *
     * @param  array{row: int, hold_ms: int}  $args
     */
    private static function holdGroupLock(array $args): mixed
    {
        $held = false;
        DB::listen(function (QueryExecuted $query) use (&$held, $args) {
            if (! $held && str_contains(strtolower($query->sql), 'for update')) {
                $held = true;
                Barrier::signal('held');
                usleep($args['hold_ms'] * 1000);
            }
        });

        $started = microtime(true);
        $row = self::row($args['row']);
        app(PaymentRecorder::class)->applyReRead($row, self::paid($row, 200000 + $row->id));

        return ['seconds' => microtime(true) - $started];
    }

    /**
     * Test 2 and 7: a new checkout (never a reused link) once $after is signalled.
     *
     * @param  array{order: int, after: string}  $args
     */
    private static function checkoutAfter(array $args): mixed
    {
        Barrier::waitFor($args['after']);
        usleep(200000); // the other worker is now inside its lock

        $started = microtime(true);
        $result = Fawaterk::checkout(Order::query()->findOrFail($args['order']), new CheckoutContext('hosted', overrides: ['reuse' => false]));

        return ['seconds' => microtime(true) - $started, 'uuid' => $result->paymentUuid];
    }

    /**
     * Test 4: the process dies right after the paid transaction commits,
     * before the events are sent (a crash, a deploy, an OOM kill).
     *
     * @param  array{row: int, total: int}  $args
     */
    private static function crashAfterCommit(array $args): mixed
    {
        $die = function () {
            exit(9);
        };
        Event::listen(PaymentPaid::class, $die);
        Event::listen(PaymentAmountMismatch::class, $die);

        $row = self::row($args['row']);
        app(PaymentRecorder::class)->applyReRead($row, self::paid($row, 300000 + $row->id, $args['total']));

        return 'not reached';
    }

    /**
     * Test 5: two checkouts of one order at the same moment.
     *
     * @param  array{order: int}  $args
     */
    private static function checkoutTogether(array $args): mixed
    {
        $order = Order::query()->findOrFail($args['order']);

        // A slow create, so the two checkouts overlap for sure: without the
        // lock, both would find no link and create one each.
        self::$fake->createTransactionUsing(function () {
            usleep(1000000);
            $key = (string) Str::uuid();

            return new TransactionIntent($key, new PaymentLink("https://fawaterk.test/ts/{$key}"), 2592000);
        });

        Barrier::wait('checkout', 2);
        $result = Fawaterk::checkout($order);

        return ['uuid' => $result->paymentUuid, 'reused' => $result->reused, 'created' => count(self::$fake->created())];
    }

    /**
     * Test 5: two re-sends of one due payment at the same moment, past the
     * reconcile lock (for example two apps sharing a database).
     *
     * @param  array{row: int}  $args
     */
    private static function redispatchTogether(array $args): mixed
    {
        $row = self::row($args['row']);
        Barrier::wait('redispatch', 2);
        app(PaymentRecorder::class)->dispatchPaid($row, onlyIfDue: true);

        return null;
    }

    /**
     * Test 5: reconcile with a slow PaymentPaid listener.
     *
     * @param  array{hold_ms: int}  $args
     */
    private static function slowReconcile(array $args): mixed
    {
        Event::listen(PaymentPaid::class, function () use ($args) {
            Barrier::signal('reconciling');
            usleep($args['hold_ms'] * 1000);
        });

        Artisan::call('fawaterk:reconcile');

        return ['output' => Artisan::output()];
    }

    /**
     * Test 5: a second reconcile while the first one is still running.
     */
    private static function secondReconcile(): mixed
    {
        Barrier::waitFor('reconciling');
        Artisan::call('fawaterk:reconcile');

        return ['output' => Artisan::output()];
    }

    /**
     * Test 7: a slow delivery inside fulfilOnce().
     *
     * @param  array{row: int, hold_ms: int}  $args
     */
    private static function slowFulfil(array $args): mixed
    {
        $ran = self::row($args['row'])->fulfilOnce(function () use ($args) {
            Barrier::signal('fulfilling');
            usleep($args['hold_ms'] * 1000);
        });

        return ['ran' => $ran];
    }

    /**
     * Test 7: group-locked re-reads of one payable's rows, again and again.
     *
     * @param  array{rows: list<int>, times: int}  $args
     */
    private static function groupLoop(array $args): mixed
    {
        Barrier::wait('loop', 2);

        for ($i = 0; $i < $args['times']; $i++) {
            $row = self::row($args['rows'][$i % count($args['rows'])]);
            app(PaymentRecorder::class)->applyReRead($row, self::unpaid($row));
        }

        return null;
    }

    /**
     * Test 7: single-row locks on the same rows, again and again.
     *
     * @param  array{rows: list<int>, times: int}  $args
     */
    private static function singleLoop(array $args): mixed
    {
        Barrier::wait('loop', 2);
        $recorder = app(PaymentRecorder::class);

        for ($i = 0; $i < $args['times']; $i++) {
            $rows = array_reverse($args['rows']);
            $recorder->recheckSoon(self::row($rows[$i % count($rows)]), 2);
            $recorder->scheduleRecheck(self::row($rows[($i + 1) % count($rows)]));
        }

        return null;
    }

    private static function row(int $id): FawaterkPayment
    {
        return Ledger::newPayment()->newQuery()->findOrFail($id);
    }

    private static function paid(FawaterkPayment $row, int $transactionId, ?int $total = null): TransactionData
    {
        return new TransactionData(
            intentKey: (string) $row->intent_key,
            transactionId: $transactionId,
            paid: true,
            totalMinor: $total ?? $row->amount_minor,
            currency: 'EGP',
            commissionMinor: null,
            paymentMethod: 'Visa-Mastercard',
            statusText: 'paid',
            paidAt: '2026-09-29 12:00:00',
        );
    }

    private static function unpaid(FawaterkPayment $row): TransactionData
    {
        return new TransactionData(
            intentKey: (string) $row->intent_key,
            transactionId: 0,
            paid: false,
            totalMinor: $row->amount_minor,
            currency: 'EGP',
            commissionMinor: null,
            paymentMethod: null,
            statusText: 'unpaid',
            paidAt: null,
        );
    }
}

<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Checkout\CheckoutContext;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\PaymentData\ReferenceCode;
use BiztechEG\Fawaterk\Data\RefundItem;
use BiztechEG\Fawaterk\Data\RefundPage;
use BiztechEG\Fawaterk\Data\TransactionData;
use BiztechEG\Fawaterk\Data\TransactionIntent;
use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentRefunded;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\UnknownPaymentPaid;
use BiztechEG\Fawaterk\Exceptions\CheckoutInTransactionException;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\Fixtures\RealClient;
use BiztechEG\Fawaterk\Tests\Fixtures\SoftOrder;
use BiztechEG\Fawaterk\Tests\Fixtures\TenantOrder;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Edge cases of the ledger and the webhooks.
 */
class EdgeCasesTest extends LedgerTestCase
{
    public function test_provider_dates_are_stored_as_the_right_instant(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $payment = $this->started();

        app(PaymentRecorder::class)->applyReRead($payment, new TransactionData(
            (string) $payment->intent_key, 555, true, 15000, 'EGP', null, 'Fawry', 'paid', '2026-09-29 12:00:00',
        ));

        // 12:00 in Cairo (UTC+3 in September) is 09:00 UTC.
        $this->assertSame('2026-09-29T09:00:00Z', $payment->refresh()->paid_at?->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_a_reference_expiry_in_cairo_is_stored_and_returned_as_the_right_instant(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 00:00:00', 'UTC'));
        $this->fake->createTransactionUsing(fn () => new TransactionIntent(
            (string) Str::uuid(),
            new ReferenceCode('981335305', new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('Africa/Cairo'))),
            2592000,
        ));

        $result = Fawaterk::checkout($this->order(), 'fawry');
        $payment = $this->payment($result->paymentUuid);

        $this->assertSame('2026-10-01T09:00:00Z', $payment->reference_expires_at?->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-10-01T09:00:00Z', $payment->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-10-01T09:00:00+00:00', $result->jsonSerialize()['expires_at']);
    }

    public function test_two_refunds_of_the_same_amount_are_both_counted(): void
    {
        Event::fake([PaymentRefunded::class, PaymentRefundReported::class]);
        $payment = $this->paidPayment(555);

        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(10, '3', 555, 5000, 'approved')]));
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        $this->travel(2)->minutes();
        $this->fake->setRefundPage(new RefundPage(1, 1, [
            new RefundItem(10, '3', 555, 5000, 'approved'),
            new RefundItem(11, '3', 555, 5000, 'approved'),
        ]));
        $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

        $this->assertSame(10000, $payment->refresh()->refunded_amount_minor);
        Event::assertDispatchedTimes(PaymentRefunded::class, 2);
        Event::assertNotDispatched(PaymentRefundReported::class);
    }

    public function test_a_failed_webhook_is_not_lost_when_the_re_read_fails(): void
    {
        $payment = $this->started();
        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down', 0));
        $this->send(SignedWebhook::failed((string) $payment->intent_key))->assertStatus(503);

        $this->assertTrue($payment->refresh()->hasFlag(Flag::FailureReported), 'recorded before the re-read');

        $this->fake->markPaid((string) $payment->intent_key);
        $this->fake->getTransactionUsing(null);
        $this->travel(11)->seconds(); // Fawaterk's retry
        $this->send(SignedWebhook::failed((string) $payment->intent_key))->assertOk();

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'the retry was re-read, not skipped by the cooldown');
    }

    public function test_auto_commission_with_the_method_list_down_is_retried_not_blocked(): void
    {
        Event::fake([PaymentPaid::class, PaymentAmountMismatch::class]);
        config()->set('fawaterk.commission', 'auto');
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, totalMinor: 15250, paymentMethod: 'Fawry', commissionMinor: 250);
        $this->fake->failPaymentMethods(new ServiceUnavailableException('down', 0));

        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertStatus(503);
        $this->assertSame(PaymentStatus::Created, $payment->refresh()->status);
        $this->assertNull($payment->flags);

        $this->fake->failPaymentMethods(null);
        $this->travel(11)->seconds(); // Fawaterk's retry
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertFalse($payment->refresh()->hasBlockingFlag());
        Event::assertDispatched(PaymentPaid::class);
        Event::assertNotDispatched(PaymentAmountMismatch::class);
    }

    public function test_a_global_scope_does_not_hide_the_payable_from_webhooks(): void
    {
        Event::fake([PaymentPaid::class]);
        TenantOrder::$currentUser = 7;
        $order = TenantOrder::query()->create(['number' => 'T-1', 'total_minor' => 15000, 'user_id' => 7]);
        $payment = $this->payment(Fawaterk::checkout($order)->paymentUuid);

        TenantOrder::$currentUser = null; // a webhook has no tenant
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertFalse($payment->refresh()->hasBlockingFlag());
        Event::assertDispatched(PaymentPaid::class);
    }

    public function test_the_paid_transaction_id_replaces_an_earlier_attempt(): void
    {
        $payment = $this->started();
        $payment->forceFill(['fawaterk_transaction_id' => 111])->save();

        $this->fake->markPaid((string) $payment->intent_key, transactionId: 222);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 222))->assertOk();

        $this->assertSame(222, $payment->refresh()->fawaterk_transaction_id);
    }

    public function test_blocked_and_refunded_rows_never_starve_re_dispatch(): void
    {
        $failing = true;
        $sent = [];
        Event::listen(PaymentPaid::class, function (PaymentPaid $event) use (&$failing, &$sent) {
            $sent[] = $event->payment->uuid;
            if ($failing) {
                throw new RuntimeException('listener down');
            }
        });

        // Two settled anomalies and one fully refunded, unfulfilled payment come first.
        foreach ([1, 2] as $i) {
            $blocked = $this->started();
            $this->fake->markPaid((string) $blocked->intent_key, totalMinor: 1);
            $this->send(SignedWebhook::paid((string) $blocked->intent_key));
        }
        $refunded = $this->paidPayment(777);
        $this->fake->setRefundPage(new RefundPage(1, 1, [new RefundItem(90, '3', 777, 15000, 'approved')]));
        $this->send(SignedWebhook::refund(777, '150.00'));
        $this->assertSame(PaymentStatus::Refunded, $refunded->refresh()->status);
        $this->assertNull($refunded->next_dispatch_at);
        // Even with a dispatch time left over, a refunded row is never selected.
        $refunded->forceFill(['next_dispatch_at' => now()])->save();

        $good = $this->paidPayment(888);
        $sent = [];

        foreach (range(1, 3) as $run) {
            $this->travel(20)->minutes();
            app(Reconciler::class)->run(2);
        }

        $this->assertContains($good->uuid, $sent, 'the good row was re-sent despite the limit');
        $this->assertNotContains($refunded->uuid, $sent, 'a refunded payment never gets PaymentPaid again');
    }

    public function test_re_dispatch_backs_off(): void
    {
        Event::listen(PaymentPaid::class, fn () => throw new RuntimeException('listener down'));
        $payment = $this->paidPayment(555);

        foreach (range(1, 10) as $minute) {
            $this->travel(1)->minutes();
            app(Reconciler::class)->run();
        }

        $attempts = $payment->refresh()->fulfil_attempts;
        $this->assertGreaterThan(1, $attempts);
        $this->assertLessThan(5, $attempts, 'not re-sent every minute');
        $this->assertNotNull($payment->next_dispatch_at);
    }

    public function test_simulate_shows_the_database_and_asks_first(): void
    {
        $payment = $this->started();

        $this->artisan('fawaterk:simulate', ['type' => 'paid', 'payment' => $payment->uuid])
            ->expectsConfirmation('Change this payment in the ['.config('database.default').'] database?', 'no')
            ->assertExitCode(1);
        $this->assertSame(PaymentStatus::Created, $payment->refresh()->status);

        $this->artisan('fawaterk:simulate', ['type' => 'paid', 'payment' => $payment->uuid, '--force' => true])->assertExitCode(0);
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    // Round 2 of the review.

    public function test_paid_at_in_any_format_never_blocks_the_payment(): void
    {
        foreach (['2026-09-29T12:00:00.000000Z' => '2026-09-29T12:00:00Z', '2026-09-29T15:00:00+03:00' => '2026-09-29T12:00:00Z', 'yesterday-ish' => null] as $paidAt => $expected) {
            $payment = $this->started();
            $this->travelTo(Carbon::parse('2026-09-30 08:00:00', 'UTC'));

            app(PaymentRecorder::class)->applyReRead($payment, new TransactionData(
                (string) $payment->intent_key, 555, true, 15000, 'EGP', null, 'Fawry', 'paid', $paidAt,
            ));

            $payment->refresh();
            $this->assertSame(PaymentStatus::Paid, $payment->status, $paidAt);
            $this->assertSame($expected ?? '2026-09-30T08:00:00Z', $payment->paid_at?->utc()->format('Y-m-d\TH:i:s\Z'), $paidAt);
            $this->travelBack();
        }
    }

    public function test_a_payment_fulfilled_meanwhile_is_not_sent_again(): void
    {
        $sent = 0;
        Event::listen(PaymentPaid::class, function () use (&$sent) {
            $sent++;
            if ($sent === 1) {
                throw new RuntimeException('first attempt fails');
            }
        });
        $payment = $this->paidPayment(555);
        $stale = $payment->fresh();
        $payment->refresh()->markFulfilled(); // the app fulfils it while reconcile holds $stale

        app(PaymentRecorder::class)->dispatchPaid($stale);

        $this->assertSame(1, $sent);
    }

    public function test_a_refunded_payment_never_gets_paid_again_and_leaves_the_queue(): void
    {
        Event::listen(PaymentPaid::class, fn () => throw new RuntimeException('not delivered'));
        $payment = $this->paidPayment(555);
        $stale = $payment->fresh();

        // Refunded in full while reconcile still holds the paid row.
        $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
        $sent = 0;
        Event::listen(PaymentPaid::class, function () use (&$sent) {
            $sent++;
        });

        app(PaymentRecorder::class)->dispatchPaid($stale);

        $this->assertSame(0, $sent);
        $this->assertNull($payment->refresh()->next_dispatch_at, 'never selected again');
    }

    public function test_simulate_pays_cleanly_in_auto_commission_mode(): void
    {
        config()->set('fawaterk.commission', 'auto');
        $payment = $this->started();

        $this->artisan('fawaterk:simulate', ['type' => 'paid', 'payment' => $payment->uuid, '--force' => true])->assertExitCode(0);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertFalse($payment->hasBlockingFlag());
    }

    public function test_a_soft_deleted_payable_gets_no_checkout(): void
    {
        Schema::table('orders', fn ($table) => $table->softDeletes());
        $order = SoftOrder::query()->create(['number' => 'S-1', 'total_minor' => 15000, 'user_id' => 7]);
        $order->delete();

        $this->assertRaises(fn () => Fawaterk::checkout($order), InvalidRequestException::class);
        $this->fake->assertNothingCreated();
    }

    public function test_a_paid_or_failed_webhook_that_does_not_end_paid_is_re_checked_soon(): void
    {
        $cases = [
            'paid, Fawaterk not updated yet' => [fn (string $key) => null, fn (string $key) => SignedWebhook::paid($key), 200],
            'paid, Fawaterk down' => [fn () => throw new ServiceUnavailableException('down', 0), fn (string $key) => SignedWebhook::paid($key), 503],
            'failed' => [fn (string $key) => null, fn (string $key) => SignedWebhook::failed($key), 200],
            'failed, Fawaterk down' => [fn () => throw new ServiceUnavailableException('down', 0), fn (string $key) => SignedWebhook::failed($key), 503],
        ];

        foreach ($cases as $case => [$reRead, $webhook, $status]) {
            $payment = $this->started();
            $payment->forceFill(['next_check_at' => now()->addHour()])->save();
            $this->fake->getTransactionUsing($reRead);

            $this->send($webhook((string) $payment->intent_key))->assertStatus($status);

            $this->assertTrue($payment->refresh()->next_check_at->lte(now()->addMinutes(2)), $case);
            $this->fake->getTransactionUsing(null);
        }
    }

    public function test_replayed_webhooks_never_postpone_a_re_check(): void
    {
        $payment = $this->started();
        $this->send(SignedWebhook::cancel(4266311, 'Aman', (string) $payment->intent_key)); // re-check now
        $due = $payment->refresh()->next_check_at;

        foreach (range(1, 3) as $replay) {
            $this->travel(11)->seconds();
            $this->send(SignedWebhook::paid((string) $payment->intent_key));
        }

        $this->assertTrue($payment->refresh()->next_check_at->lte($due));
    }

    public function test_apps_using_immutable_dates_work(): void
    {
        Date::use(CarbonImmutable::class);

        try {
            Event::fake([PaymentRefundReported::class]);
            $open = $this->payment(Fawaterk::checkout($this->order(), 'fawry')->paymentUuid);
            $paid = $this->paidPayment(555);
            $this->send(SignedWebhook::refund(555, '50.00'))->assertOk();

            foreach (range(1, 30) as $run) {
                $this->travel(2)->hours();
                $this->assertSame(0, app(Reconciler::class)->run()->errors, "run {$run}");
            }

            $this->assertSame(PaymentStatus::Expired, $open->refresh()->status);
            $this->assertTrue($paid->refresh()->hasFlag(Flag::RefundUnverified));
            Event::assertDispatchedTimes(PaymentRefundReported::class, 1);
        } finally {
            Date::useDefault();
        }
    }

    public function test_a_commit_retried_after_a_deadlock_sends_paid_once(): void
    {
        $sent = 0;
        Event::listen(PaymentPaid::class, function () use (&$sent) {
            $sent++;
        });
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);

        // The first commit of the paid transaction fails with a deadlock; the
        // server rolled it back, and Laravel runs the closure again.
        $armed = true;
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$armed) {
            if ($armed) {
                $armed = false;
                $event->connection->getPdo()->rollBack();

                throw new RuntimeException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction');
            }
        });

        $this->send(SignedWebhook::paid((string) $payment->intent_key))->assertOk();

        $this->assertFalse($armed, 'the deadlock was injected');
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        $this->assertSame(1, $sent, 'PaymentPaid once, not once per commit attempt');
        $this->assertSame(1, $payment->fulfil_attempts);
    }

    public function test_events_wait_for_the_callers_own_transaction(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);
        $data = $this->fake->getTransaction((string) $payment->intent_key);

        DB::transaction(function () use ($payment, $data) {
            app(PaymentRecorder::class)->applyReRead($payment, $data);
            Event::assertNotDispatched(PaymentPaid::class);
        });

        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    public function test_failed_and_unknown_re_reads_are_single_flight_during_an_outage(): void
    {
        $payment = $this->started();
        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down', 503));

        $before = $this->fake->getTransactionCalls();
        foreach (range(1, 20) as $replay) {
            $this->send(SignedWebhook::failed((string) $payment->intent_key));
        }
        $this->assertSame(1, $this->fake->getTransactionCalls() - $before, 'failed URL');

        $before = $this->fake->getTransactionCalls();
        foreach (range(1, 20) as $replay) {
            $this->send(SignedWebhook::paid('7ba7b810-9dad-11d1-80b4-00c04fd430c8'))->assertStatus(503);
        }
        $this->assertSame(1, $this->fake->getTransactionCalls() - $before, 'unknown intent (still 503, so Fawaterk retries)');
    }

    public function test_an_unknown_paid_intent_is_raised_once(): void
    {
        Event::fake([UnknownPaymentPaid::class]);
        $foreign = '7ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $this->fake->getTransactionUsing(fn (string $key) => new TransactionData($key, 99, true, 100, 'EGP', null, 'Fawry', 'paid', null));

        foreach (range(1, 3) as $replay) {
            $this->send(SignedWebhook::paid($foreign, 99))->assertOk();
            $this->travel(2)->minutes(); // past the re-read cooldown
        }

        Event::assertDispatchedTimes(UnknownPaymentPaid::class, 1);
    }

    public function test_an_unknown_paid_intent_is_retried_when_the_re_read_fails(): void
    {
        Event::fake([UnknownPaymentPaid::class]);
        $foreign = '7ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down', 0));

        $this->send(SignedWebhook::paid($foreign, 99))->assertStatus(503);

        $this->fake->getTransactionUsing(fn (string $key) => new TransactionData($key, 99, true, 100, 'EGP', null, 'Fawry', 'paid', null));
        $this->travel(11)->seconds(); // Fawaterk's retry
        $this->send(SignedWebhook::paid($foreign, 99))->assertOk();

        Event::assertDispatched(UnknownPaymentPaid::class);
    }

    public function test_a_transaction_on_the_payables_own_connection_is_refused_too(): void
    {
        config()->set('database.connections.shop', config('database.connections.testing'));
        $order = $this->order();
        $this->app->instance(FawaterkClient::class, new RealClient($this->fake));

        $this->assertRaises(
            fn () => DB::connection($order->getConnectionName())->transaction(fn () => Fawaterk::checkout($order)),
            CheckoutInTransactionException::class,
        );

        // The ledger on its own connection, the payable's connection in a transaction.
        config()->set('fawaterk.ledger.connection', 'shop');
        (include __DIR__.'/../../database/migrations/create_fawaterk_tables.php.stub')->up();
        $this->assertRaises(
            fn () => DB::connection('testing')->transaction(fn () => Fawaterk::checkout($order)),
            CheckoutInTransactionException::class,
        );
    }

    private function started(): FawaterkPayment
    {
        return $this->payment(Fawaterk::checkout($this->order(), new CheckoutContext('hosted'))->paymentUuid);
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

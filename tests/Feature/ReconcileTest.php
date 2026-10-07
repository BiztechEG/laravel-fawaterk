<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Events\PaymentExpired;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentUnfulfilled;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\TransactionNotFoundException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Reconcile\ReconcileReport;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class ReconcileTest extends LedgerTestCase
{
    public function test_a_missed_webhook_is_caught_by_reconcile(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, transactionId: 555);

        $this->reconcile();
        Event::assertNotDispatched(PaymentPaid::class, 'not due yet');

        $this->travel(6)->minutes();
        $report = $this->reconcile();

        $this->assertSame(1, $report->paid);
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        Event::assertDispatched(PaymentPaid::class);
    }

    public function test_unpaid_rows_back_off_and_a_cancel_report_is_cleared(): void
    {
        $payment = $this->started();
        $payment->forceFill(['next_check_at' => now()])->save();
        $this->send(SignedWebhook::cancel(4266311, 'Aman', (string) $payment->intent_key));

        $this->reconcile();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Created, $payment->status);
        $this->assertFalse($payment->hasFlag(Flag::CancelReported));
        $this->assertTrue($payment->next_check_at->gt(now()));
        $this->assertNotNull($payment->last_checked_at);
    }

    public function test_rows_expire_after_the_grace_period_once_a_re_read_says_unpaid(): void
    {
        Event::fake([PaymentExpired::class]);
        $unpaid = $this->started('fawry');
        $unknown = $this->started('fawry');
        $this->fake->getTransactionUsing(fn (string $key) => $key === $unknown->intent_key
            ? throw new TransactionNotFoundException('gone', 422)
            : null);

        $this->travel(2)->days();
        $this->travel(20)->minutes();
        $this->reconcile();
        $this->assertSame(PaymentStatus::Created, $unpaid->refresh()->status, 'within the grace period');

        $this->travel(15)->minutes();
        $report = $this->reconcile();

        $this->assertSame(2, $report->expired);
        $this->assertSame(PaymentStatus::Expired, $unpaid->refresh()->status);
        $this->assertSame(PaymentStatus::Expired, $unknown->refresh()->status);
        $this->assertTrue($unpaid->next_check_at->between(now()->addHours(23), now()->addDay()), 'a late payment is still looked for, daily');
        Event::assertDispatchedTimes(PaymentExpired::class, 2);
    }

    public function test_expired_rows_are_re_read_daily_for_a_week_and_a_late_payment_is_found(): void
    {
        Event::fake([PaymentPaid::class, PaymentExpired::class]);
        $late = $this->started('fawry');
        $never = $this->started('fawry');
        $this->travel(2)->days();
        $this->travel(31)->minutes();
        $this->reconcile();
        $this->assertSame(PaymentStatus::Expired, $late->refresh()->status);

        // Paid at the last minute through a Fawry outlet; Fawaterk knows a day later.
        $this->fake->markPaid((string) $late->intent_key);
        $this->travel(1)->days();
        $this->reconcile();

        $this->assertSame(PaymentStatus::Paid, $late->refresh()->status);
        $this->assertTrue($late->hasFlag(Flag::LatePayment));
        Event::assertDispatched(PaymentPaid::class, fn (PaymentPaid $event) => $event->late);

        foreach (range(1, 7) as $day) {
            $this->travel(1)->days();
            $this->reconcile();
        }
        $calls = $this->fake->getTransactionCalls();
        $this->travel(1)->days();
        $this->reconcile();

        $this->assertSame(PaymentStatus::Expired, $never->refresh()->status);
        $this->assertNull($never->next_check_at, 'given up after a week');
        $this->assertSame($calls, $this->fake->getTransactionCalls());
        Event::assertDispatchedTimes(PaymentExpired::class, 2);
    }

    public function test_an_expired_row_that_was_paid_after_all_is_paid_not_expired(): void
    {
        $payment = $this->started('fawry');
        $this->fake->markPaid((string) $payment->intent_key);

        $this->travel(3)->days();
        $this->reconcile();

        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function test_one_retry_on_connection_errors_then_the_row_waits(): void
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);
        $calls = 0;
        $this->fake->getTransactionUsing(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ServiceUnavailableException('reset', 0);
            }

            return null;
        });

        $this->travel(6)->minutes();
        $this->reconcile();
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'the retry succeeded');

        $down = $this->started();
        $this->fake->getTransactionUsing(fn () => throw new ServiceUnavailableException('down', 503));
        $this->travel(6)->minutes();
        $report = $this->reconcile();

        $this->assertSame(1, $report->errors);
        $this->assertSame(PaymentStatus::Created, $down->refresh()->status);
        $this->assertTrue($down->next_check_at->gt(now()));
    }

    public function test_unfulfilled_payments_are_sent_again_until_fulfilled_then_alerted_once(): void
    {
        $attempts = 0;
        Event::listen(PaymentPaid::class, function (PaymentPaid $event) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new RuntimeException('mail server down');
            }
            $event->payment->markFulfilled();
        });

        $payment = $this->paidPayment();
        $this->assertNull($payment->refresh()->fulfilled_at);

        $this->travel(10)->minutes(); // the first retry waits for queue lag
        $this->reconcile();
        $this->assertNull($payment->refresh()->fulfilled_at);

        $this->travel(15)->minutes(); // the second retry waits longer
        $this->reconcile();
        $this->assertNotNull($payment->refresh()->fulfilled_at);
        $this->assertSame(3, $payment->fulfil_attempts);

        $this->reconcile();
        $this->assertSame(3, $attempts, 'a fulfilled payment is left alone');
    }

    public function test_the_unfulfilled_alert_fires_once(): void
    {
        Event::fake([PaymentUnfulfilled::class]);
        $payment = $this->paidPayment();

        foreach (range(1, 7) as $run) {
            $this->travel(10)->minutes();
            $this->reconcile();
        }

        $this->assertTrue($payment->refresh()->hasFlag(Flag::UnfulfilledAlerted));
        Event::assertDispatchedTimes(PaymentUnfulfilled::class, 1);
    }

    public function test_blocking_flags_stop_re_dispatch(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, totalMinor: 100);
        $this->send(SignedWebhook::paid((string) $payment->intent_key));

        $this->travel(10)->minutes();
        $this->reconcile();
        $this->reconcile();

        $this->assertTrue($payment->refresh()->hasFlag(Flag::AmountMismatch));
        Event::assertNotDispatched(PaymentPaid::class);
    }

    public function test_the_command_writes_a_heartbeat(): void
    {
        $this->artisan('fawaterk:reconcile')->expectsOutputToContain('Checked 0')->assertExitCode(0);

        $this->assertIsInt(Cache::get('fawaterk:default:staging:reconcile:heartbeat'));
    }

    public function test_one_reconcile_at_a_time_through_the_packages_cache_store(): void
    {
        config()->set('fawaterk.cache_store', 'file');
        $lock = Cache::store('file')->lock('fawaterk:default:staging:reconcile:running', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('fawaterk:reconcile')->expectsOutputToContain('Another fawaterk:reconcile is running')->assertExitCode(0);
        } finally {
            $lock->release();
        }

        $this->artisan('fawaterk:reconcile')->expectsOutputToContain('Checked 0')->assertExitCode(0);
    }

    public function test_simulate_runs_the_real_pipeline_but_never_on_live_or_production(): void
    {
        Event::fake([PaymentPaid::class]);
        $payment = $this->started();

        $this->artisan('fawaterk:simulate', ['type' => 'paid', 'payment' => $payment->uuid, '--force' => true])->assertExitCode(0);
        $this->assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        Event::assertDispatched(PaymentPaid::class);

        $this->artisan('fawaterk:simulate', ['type' => 'refund', 'payment' => $payment->uuid, '--amount' => '50.00', '--force' => true])->assertExitCode(0);
        $this->assertSame(5000, $payment->refresh()->refunded_amount_minor);

        config()->set('fawaterk.environment', 'live');
        $this->artisan('fawaterk:simulate', ['type' => 'paid', 'payment' => $payment->uuid])->assertExitCode(1);

        config()->set('fawaterk.environment', 'staging');
        $this->app['env'] = 'production';
        $this->artisan('fawaterk:simulate', ['type' => 'paid', 'payment' => $payment->uuid])->assertExitCode(1);
    }

    private function reconcile(): ReconcileReport
    {
        return app(Reconciler::class)->run();
    }

    private function started(string $profile = 'hosted'): FawaterkPayment
    {
        return $this->payment(Fawaterk::checkout($this->order(), $profile)->paymentUuid);
    }

    private function paidPayment(): FawaterkPayment
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key);
        $this->send(SignedWebhook::paid((string) $payment->intent_key));

        return $payment->refresh();
    }

    private function send(SignedWebhook $webhook): void
    {
        $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson())->assertOk();
    }
}

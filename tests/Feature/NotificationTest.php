<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Events\PaymentAmountMismatch;
use BiztechEG\Fawaterk\Events\PaymentFailureReported;
use BiztechEG\Fawaterk\Events\PaymentOrderChanged;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Events\PaymentPaidTwice;
use BiztechEG\Fawaterk\Events\PaymentPending;
use BiztechEG\Fawaterk\Events\PaymentRefundReported;
use BiztechEG\Fawaterk\Events\PaymentUnfulfilled;
use BiztechEG\Fawaterk\Events\RefundWebhookMisrouted;
use BiztechEG\Fawaterk\Events\UnknownPaymentPaid;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Notifications\AnomalyReport;
use BiztechEG\Fawaterk\Notifications\PaymentAnomalyNotification;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Testing\SignedWebhook;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Mime\Email;

class NotificationTest extends LedgerTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('fawaterk.notifications.mail', 'ops@shop.test, not-an-address, owner@shop.test');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_default_events_are_the_blocking_and_operations_ones(): void
    {
        $this->assertSame([
            PaymentAmountMismatch::class,
            PaymentPaidTwice::class,
            PaymentOrderChanged::class,
            PaymentUnfulfilled::class,
            UnknownPaymentPaid::class,
            PaymentRefundReported::class,
            RefundWebhookMisrouted::class,
        ], config('fawaterk.notifications.events'));
    }

    public function test_a_refund_webhook_at_the_wrong_url_is_mailed_once(): void
    {
        Notification::fake();

        $webhook = SignedWebhook::refund(555, '40');
        foreach ([1, 2] as $attempt) {
            $this->call('POST', '/fawaterk/webhooks/failed_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson())->assertOk();
        }
        $this->app->terminate();

        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);
        Notification::assertSentTo(new AnonymousNotifiable, PaymentAnomalyNotification::class, fn (PaymentAnomalyNotification $notification) => $notification->report->kind === 'refund_webhook_misrouted'
            && $notification->report->facts['received_at'] === 'failed'
            && $notification->report->facts['transaction'] === '555'
            && $notification->report->facts['refund'] === '40 EGP');
    }

    public function test_an_amount_mismatch_is_mailed_to_the_configured_addresses(): void
    {
        Notification::fake();
        $payment = $this->paid(totalMinor: 14999);

        Notification::assertSentTo(new AnonymousNotifiable, PaymentAnomalyNotification::class, function (PaymentAnomalyNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($payment) {
            return $notifiable->routes['mail'] === ['ops@shop.test', 'owner@shop.test']
                && $notification->report->kind === 'amount_mismatch'
                && $notification->report->facts['payment'] === $payment->uuid
                && $notification->report->facts['expected'] === '150.00 EGP'
                && $notification->report->facts['paid'] === '149.99 EGP';
        });
        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);
    }

    public function test_the_mail_is_really_sent_with_no_customer_data(): void
    {
        $payment = $this->paid(totalMinor: 14999);

        $messages = $this->app->make('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        /** @var Email $email */
        $email = $messages[0]->getOriginalMessage();
        $text = $email->getTextBody().$email->getHtmlBody();

        $this->assertStringContainsString('[Fawaterk] Amount mismatch', (string) $email->getSubject());
        $this->assertStringContainsString($payment->uuid, $text);
        $this->assertSame(['ops@shop.test', 'owner@shop.test'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));

        $order = $payment->payable;
        foreach (['customer@example.test', 'Test Customer', (string) $order?->getAttribute('number'), (string) $payment->intent_key, (string) $payment->checkout_url] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_a_blocking_flag_is_mailed_once_even_when_reconcile_runs_again(): void
    {
        Notification::fake();
        $payment = $this->paid(totalMinor: 14999);

        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->app->make(Reconciler::class)->run();
        $this->app->make(Reconciler::class)->run();
        $this->app->terminate();
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 555))->assertOk();

        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);
    }

    public function test_informational_events_are_not_mailed_by_default(): void
    {
        Notification::fake();
        $payment = $this->started();

        $this->send(SignedWebhook::failed((string) $payment->intent_key, 555))->assertOk();
        event(new PaymentFailureReported($payment, 555));
        $this->app->terminate();

        Notification::assertNothingSent();
    }

    public function test_the_app_can_choose_the_events(): void
    {
        config()->set('fawaterk.notifications.events', [PaymentFailureReported::class]);
        Notification::fake();
        $payment = $this->started();

        $this->send(SignedWebhook::failed((string) $payment->intent_key, 555))->assertOk();

        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);
    }

    public function test_no_recipients_means_no_mail(): void
    {
        Notification::fake();
        config()->set('fawaterk.notifications.mail', null);

        $this->paid(totalMinor: 14999);

        Notification::assertNothingSent();
    }

    public function test_the_app_can_route_the_mail_itself(): void
    {
        Notification::fake();
        $events = [];
        Fawaterk::routeNotificationsUsing(function (object $event) use (&$events) {
            $events[] = $event::class;

            return $event instanceof PaymentAmountMismatch ? ['finance@shop.test', 'bad address'] : null;
        });

        $this->paid(totalMinor: 14999);
        event(new UnknownPaymentPaid((string) Str::uuid(), 9));
        $this->app->terminate();

        $this->assertSame([PaymentAmountMismatch::class, UnknownPaymentPaid::class], $events);
        Notification::assertSentTo(new AnonymousNotifiable, PaymentAnomalyNotification::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === ['finance@shop.test']);
        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);
    }

    public function test_a_notifiable_returned_by_the_router_is_notified(): void
    {
        Notification::fake();
        $admin = (new AnonymousNotifiable)->route('mail', 'admin@shop.test');
        Fawaterk::routeNotificationsUsing(fn () => [$admin]);

        event(new UnknownPaymentPaid((string) Str::uuid(), 9));
        $this->app->terminate();

        Notification::assertSentTo($admin, PaymentAnomalyNotification::class);
    }

    public function test_a_broken_mailer_never_fails_the_webhook_or_the_apps_listeners(): void
    {
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('SMTP down');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('SMTP down');
            }
        });
        $seen = 0;
        Event::listen(PaymentAmountMismatch::class, function () use (&$seen) {
            $seen++;
        });

        $payment = $this->paid(totalMinor: 14999);

        $this->assertSame(1, $seen, "the app's own listener still ran");
        $this->assertNull($payment->refresh()->next_alert_at, 'the alert counts as sent: there is no mail retry');
        $this->assertTrue($payment->hasBlockingFlag(), 'the flag stays for doctor and the app');
    }

    public function test_a_throwing_router_never_fails_the_webhook_or_the_apps_listeners(): void
    {
        Fawaterk::routeNotificationsUsing(fn () => throw new RuntimeException('app bug'));
        $seen = 0;
        Event::listen(PaymentAmountMismatch::class, function () use (&$seen) {
            $seen++;
        });

        $payment = $this->paid(totalMinor: 14999);

        $this->assertSame(1, $seen, "the app's own listener still ran");
        $this->assertTrue($payment->hasBlockingFlag());
    }

    public function test_a_queue_connection_queues_the_mail(): void
    {
        Queue::fake();
        config()->set('fawaterk.notifications.queue', 'database');

        $this->paid(totalMinor: 14999);

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->connection === 'database');
        $this->assertCount(0, $this->app->make('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_reports_for_events_without_a_payment_row(): void
    {
        $unknown = AnomalyReport::fromEvent(new UnknownPaymentPaid('5b1f0d2e-6f7a-4c1b-9a8e-0c2d3e4f5a6b', 91));
        $this->assertSame('unknown_payment_paid', $unknown->kind);
        $this->assertSame('5b1f0d2e-6f7a-4c1b-9a8e-0c2d3e4f5a6b', $unknown->facts['intent_key']);
        $this->assertSame('91', $unknown->facts['transaction']);

        $refund = AnomalyReport::fromEvent(new PaymentRefundReported(null, 91, '50.5', 'EGP'));
        $this->assertSame('refund_reported', $refund->kind);
        $this->assertSame('50.5 EGP', $refund->facts['refund']);

        $missing = AnomalyReport::fromEvent(new PaymentOrderChanged($this->started(), payableMissing: true));
        $this->assertSame('payable_missing', $missing->kind);

        $mail = (new PaymentAnomalyNotification($refund))->toMail(new AnonymousNotifiable);
        $this->assertSame('[Fawaterk] Refund not confirmed', $mail->subject);
        $this->assertContains('Fawaterk transaction: 91', $mail->introLines);
    }

    public function test_the_mail_waits_until_the_webhook_has_answered(): void
    {
        Notification::fake();
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, totalMinor: 14999, transactionId: 555);
        $kernel = $this->app->make(Kernel::class);
        $request = Request::create('/fawaterk/webhooks/paid_json', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], SignedWebhook::paid((string) $payment->intent_key, 555)->toJson());

        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        Notification::assertNothingSent();

        $kernel->terminate($request, $response);
        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);

        $kernel->terminate($request, $response);
        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);
    }

    public function test_a_queue_job_sends_its_mails_when_it_ends(): void
    {
        Notification::fake();

        event(new UnknownPaymentPaid((string) Str::uuid(), 9));
        Notification::assertNothingSent();

        event(new JobProcessed('database', new \stdClass));
        Notification::assertSentTimes(PaymentAnomalyNotification::class, 1);
    }

    public function test_the_mailer_leaves_paid_and_pending_alone(): void
    {
        // Apps (and fawaterk:doctor) can still tell whether anything delivers on PaymentPaid.
        $this->assertFalse($this->app['events']->hasListeners(PaymentPaid::class));
        $this->assertFalse($this->app['events']->hasListeners(PaymentPending::class));
        $this->assertTrue($this->app['events']->hasListeners(PaymentAmountMismatch::class));
    }

    public function test_the_mail_follows_the_app_locale(): void
    {
        $this->app->setLocale('ar');
        $report = AnomalyReport::fromEvent(new PaymentPaidTwice($this->started()));

        $mail = (new PaymentAnomalyNotification($report))->toMail(new AnonymousNotifiable);

        $this->assertStringContainsString('دفع مكرر', (string) $mail->subject);
    }

    private function started(): FawaterkPayment
    {
        return $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
    }

    private function paid(int $totalMinor): FawaterkPayment
    {
        $payment = $this->started();
        $this->fake->markPaid((string) $payment->intent_key, totalMinor: $totalMinor, transactionId: 555);
        $this->send(SignedWebhook::paid((string) $payment->intent_key, 555))->assertOk();

        return $payment->refresh();
    }

    private function send(SignedWebhook $webhook): TestResponse
    {
        return $this->call('POST', '/fawaterk/webhooks/'.$webhook->segment(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $webhook->toJson());
    }
}

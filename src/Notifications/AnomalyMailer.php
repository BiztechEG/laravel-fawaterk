<?php

namespace BiztechEG\Fawaterk\Notifications;

use BiztechEG\Fawaterk\Events;
use BiztechEG\Fawaterk\Support\Callbacks;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Throwable;

/**
 * Listens to the package's anomaly events and mails those listed in
 * fawaterk.notifications.events (read when the event fires).
 *
 * It never throws: an exception here would stop the app's own listeners of
 * the same event. A failed send goes to the exception handler, and the flag
 * stays on the payment for fawaterk:doctor and the app to see.
 *
 * Mail is sent after the response (PendingAlerts), or queued when
 * fawaterk.notifications.queue names a connection.
 *
 * @internal
 */
final class AnomalyMailer
{
    /**
     * The events the mailer can send. PaymentPaid and PaymentPending are not
     * among them: they are not anomalies, and an app must be able to tell
     * whether anything of its own listens to PaymentPaid.
     */
    public const EVENTS = [
        Events\PaymentAmountMismatch::class,
        Events\PaymentPaidTwice::class,
        Events\PaymentOrderChanged::class,
        Events\PaymentUnfulfilled::class,
        Events\UnknownPaymentPaid::class,
        Events\PaymentRefundReported::class,
        Events\RefundWebhookMisrouted::class,
        Events\PaymentRefunded::class,
        Events\PaymentFailureReported::class,
        Events\PaymentCancelReported::class,
        Events\PaymentExpired::class,
    ];

    public function __construct(
        private readonly Dispatcher $notifications,
        private readonly Callbacks $callbacks,
        private readonly Config $config,
        private readonly PendingAlerts $pending,
    ) {}

    public function handle(object $event): void
    {
        try {
            if (! in_array($event::class, (array) $this->config->get('fawaterk.notifications.events', []), true)) {
                return;
            }

            $recipients = $this->recipients($event);

            if ($recipients === []) {
                return;
            }

            $notification = new PaymentAnomalyNotification(AnomalyReport::fromEvent($event));
            $queue = $this->config->get('fawaterk.notifications.queue');

            if (is_string($queue) && $queue !== '') {
                $this->notifications->send($recipients, $notification->onConnection($queue));
            } else {
                $this->pending->push($recipients, $notification);
            }
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
                // Nothing else can be done; the flag is on the row.
            }
        }
    }

    /**
     * @return list<object>
     */
    private function recipients(object $event): array
    {
        $router = $this->callbacks->notificationRouter;
        $routed = $router === null ? $this->config->get('fawaterk.notifications.mail') : $router($event);

        $addresses = [];
        $notifiables = [];

        foreach (is_iterable($routed) ? $routed : [$routed] as $item) {
            if (is_string($item)) {
                array_push($addresses, ...self::addresses($item));
            } elseif (is_object($item) && method_exists($item, 'routeNotificationFor')) {
                $notifiables[] = $item;
            }
        }

        if ($addresses !== []) {
            $notifiables[] = (new AnonymousNotifiable)->route('mail', array_values(array_unique($addresses)));
        }

        return $notifiables;
    }

    /**
     * @return list<string>
     */
    private static function addresses(string $list): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $list)),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}

<?php

namespace BiztechEG\Fawaterk\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Notifications\Dispatcher;
use Throwable;

/**
 * Anomaly mails waiting for the end of the request, so a slow mail
 * server never holds up the webhook's answer to Fawaterk, a result page or a
 * reconcile run.
 *
 * They are sent when the app terminates (after the HTTP response is sent, or
 * when an Artisan command ends), after each queue job, and at PHP shutdown as
 * a last resort. Each mail is sent once: flush() empties the list.
 *
 * @internal
 */
final class PendingAlerts
{
    /** @var list<array{0: list<object>, 1: PaymentAnomalyNotification}> */
    private array $pending = [];

    private bool $shutdownHooked = false;

    public function __construct(private readonly Container $app) {}

    /**
     * @param  list<object>  $recipients
     */
    public function push(array $recipients, PaymentAnomalyNotification $notification): void
    {
        $this->pending[] = [$recipients, $notification];

        if (! $this->shutdownHooked) {
            $this->shutdownHooked = true;
            register_shutdown_function(fn () => $this->flush());
        }
    }

    public function count(): int
    {
        return count($this->pending);
    }

    /**
     * Sends what is waiting. Never throws: a failed send is reported, and the
     * flag stays on the payment.
     */
    public function flush(): void
    {
        while ($this->pending !== []) {
            [$recipients, $notification] = array_shift($this->pending);

            try {
                $this->app->make(Dispatcher::class)->sendNow($recipients, $notification);
            } catch (Throwable $e) {
                try {
                    report($e);
                } catch (Throwable) {
                    // Nothing else can be done; the flag is on the row.
                }
            }
        }
    }
}

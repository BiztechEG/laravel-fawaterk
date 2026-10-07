<?php

namespace BiztechEG\Fawaterk\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An anomaly mail for the people who run the shop. It carries only
 * ledger facts and ids, never customer data.
 *
 * The package sends it at once unless fawaterk.notifications.queue names a
 * queue connection.
 */
class PaymentAnomalyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AnomalyReport $report) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $kind = $this->report->kind;
        $known = trans()->has('fawaterk::notifications.kinds.'.$kind.'.subject');
        $subject = $known
            ? __('fawaterk::notifications.kinds.'.$kind.'.subject')
            : __('fawaterk::notifications.other.subject', ['kind' => $kind]);

        $message = (new MailMessage)
            ->subject('[Fawaterk] '.$subject.(isset($this->report->facts['payment']) ? ' · '.substr($this->report->facts['payment'], 0, 8) : ''))
            ->greeting($subject)
            ->line($known ? __('fawaterk::notifications.kinds.'.$kind.'.body') : __('fawaterk::notifications.other.body'));

        foreach ($this->report->facts as $label => $value) {
            $message->line(__('fawaterk::notifications.facts.'.$label).': '.$value);
        }

        return $message->line(__('fawaterk::notifications.footer'));
    }
}

<?php

namespace BiztechEG\Fawaterk\Tests\MySql\Fixtures;

use BiztechEG\Fawaterk\Events\PaymentPaid;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An app's queued PaymentPaid listener on the database queue.
 */
class FulfilOnQueue implements ShouldQueue
{
    public string $connection = 'database';

    public function handle(PaymentPaid $event): void
    {
        $event->payment->markFulfilled();
    }
}

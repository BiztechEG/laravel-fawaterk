<?php

namespace BiztechEG\Fawaterk\Console;

use BiztechEG\Fawaterk\Reconcile\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Schedule it every five minutes:
 *
 *     $schedule->command('fawaterk:reconcile')->everyFiveMinutes()->withoutOverlapping(15);
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'fawaterk:reconcile {--limit=100 : Most rows handled per section in one run}';

    protected $description = 'Re-check open Fawaterk payments, expire old ones and re-send PaymentPaid for unfulfilled ones';

    public function handle(Reconciler $reconciler, CacheFactory $cache): int
    {
        // The package's own store (fawaterk.cache_store), shared by every server.
        $store = $cache->store(config('fawaterk.cache_store'))->getStore();
        $lock = $store instanceof LockProvider ? $store->lock($reconciler->lockKey(), 900) : null;

        if ($lock !== null && ! $lock->get()) {
            $this->line('Another fawaterk:reconcile is running.');

            return self::SUCCESS;
        }

        try {
            $report = $reconciler->run(max(1, (int) $this->option('limit')));
        } finally {
            $lock?->release();
        }

        $this->line(sprintf(
            'Checked %d, paid %d, expired %d, refunds checked %d, re-sent %d, errors %d.',
            $report->checked, $report->paid, $report->expired, $report->refundsChecked, $report->redispatched, $report->errors,
        ));

        return $report->errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

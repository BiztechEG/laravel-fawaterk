<?php

namespace BiztechEG\Fawaterk\Console;

use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Publishes the config and the migration, then prints what to add by hand:
 * the .env keys, the routes and the schedule. It never writes .env, routes
 * or the schedule, and never migrates.
 */
final class InstallCommand extends Command
{
    protected $signature = 'fawaterk:install
        {--force : Overwrite config/fawaterk.php if it exists}
        {--no-doctor : Do not run fawaterk:doctor at the end}';

    protected $description = 'Publish the Fawaterk config and migration, and show the .env keys, routes and schedule to add';

    /**
     * The keys to put in .env, with whether each is required.
     */
    private const ENV = [
        'FAWATERK_ENV' => 'staging or live',
        'FAWATERK_CLIENT_ID' => 'required (Fawaterk → Integrations → OAuth client credentials)',
        'FAWATERK_CLIENT_SECRET' => 'required',
        'FAWATERK_VENDOR_API_KEY' => 'required (signs webhooks; treat it as a payment credential)',
        'FAWATERK_APP_URL' => 'optional, an https origin; defaults to APP_URL',
        'FAWATERK_RESULT_KEY' => 'optional, 32+ random characters; defaults to a key derived from APP_KEY',
        'FAWATERK_ALERT_MAIL' => 'recommended, comma-separated addresses for anomaly mail',
        'FAWATERK_CACHE_STORE' => 'optional, a cache store with locks (redis, database, memcached, dynamodb)',
    ];

    public function handle(): int
    {
        $this->publishConfig();
        $this->publishMigration();
        $this->env();
        $this->routes();
        $this->schedule();

        $this->newLine();
        $this->line('Then set fawaterk.ledger.payable_key_type if your payable models do not use integer keys, and run php artisan migrate.');

        if (! $this->option('no-doctor')) {
            $this->newLine();
            $this->line('<comment>Running fawaterk:doctor --offline</comment> (run it again without --offline once .env is filled in):');
            $this->call('fawaterk:doctor', ['--offline' => true]);
        }

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        if (file_exists(config_path('fawaterk.php')) && ! $this->option('force')) {
            $this->line('config/fawaterk.php already exists (use --force to overwrite it).');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'fawaterk-config', '--force' => true]);
        $this->line('Published config/fawaterk.php.');
    }

    private function publishMigration(): void
    {
        $existing = glob(database_path('migrations/*_create_fawaterk_tables.php')) ?: [];

        if ($existing !== []) {
            $this->line('The Fawaterk migration is already published: '.basename($existing[0]).'.');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'fawaterk-migrations']);
        $published = glob(database_path('migrations/*_create_fawaterk_tables.php')) ?: [];
        $this->line('Published '.($published === [] ? 'the migration' : 'database/migrations/'.basename($published[0])).'.');
    }

    private function env(): void
    {
        $this->section('Add to .env (values are never printed)');

        $set = [
            'FAWATERK_CLIENT_ID' => config('fawaterk.client_id'),
            'FAWATERK_CLIENT_SECRET' => config('fawaterk.client_secret'),
            'FAWATERK_VENDOR_API_KEY' => config('fawaterk.vendor_api_key'),
            'FAWATERK_APP_URL' => config('fawaterk.app_url'),
            'FAWATERK_RESULT_KEY' => config('fawaterk.results.key'),
            'FAWATERK_ALERT_MAIL' => config('fawaterk.notifications.mail'),
            'FAWATERK_CACHE_STORE' => config('fawaterk.cache_store'),
        ];

        foreach (self::ENV as $key => $note) {
            $state = $key === 'FAWATERK_ENV'
                ? (string) config('fawaterk.environment')
                : (is_string($set[$key] ?? null) && trim((string) $set[$key]) !== '' ? 'set' : 'not set');

            $this->line(sprintf('  %-24s %-9s %s', $key.'=', '['.$state.']', $note));
        }
    }

    private function routes(): void
    {
        $modern = version_compare($this->laravel->version(), '11.0', '>=');

        $this->section('Add the routes');
        $this->line('  // '.($modern ? 'routes/web.php (or routes/api.php if you installed it)' : 'routes/api.php').': the webhook URLs for the Fawaterk dashboard');
        $this->line("  Route::fawaterkWebhooks('fawaterk/webhooks');");
        $this->newLine();
        $this->line('  // routes/web.php: the page Fawaterk sends the payer back to');
        $this->line("  Route::fawaterk('fawaterk');");
    }

    private function schedule(): void
    {
        $modern = version_compare($this->laravel->version(), '11.0', '>=');
        $prune = "['--model' => [\\".WebhookEvent::class.'::class]]';

        $this->section('Add to the schedule');

        if ($modern) {
            $this->line('  // routes/console.php');
            $this->line('  use Illuminate\Support\Facades\Schedule;');
            $this->newLine();
            $this->line("  Schedule::command('fawaterk:reconcile')->everyFiveMinutes()->withoutOverlapping(15);");
            $this->line("  Schedule::command('model:prune', {$prune})->daily();");
        } else {
            $this->line('  // app/Console/Kernel.php, in schedule()');
            $this->line("  \$schedule->command('fawaterk:reconcile')->everyFiveMinutes()->withoutOverlapping(15);");
            $this->line("  \$schedule->command('model:prune', {$prune})->daily();");
        }

        $this->line('  // and the cron entry: * * * * * php /path/to/artisan schedule:run');
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment>");
    }
}

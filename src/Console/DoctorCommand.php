<?php

namespace BiztechEG\Fawaterk\Console;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Auth\AccessTokenProvider;
use BiztechEG\Fawaterk\Checkout\Profile;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Environment;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Exceptions\AuthenticationException;
use BiztechEG\Fawaterk\Exceptions\FawaterkException;
use BiztechEG\Fawaterk\Http\Controllers\WebhookController;
use BiztechEG\Fawaterk\Ledger\ExpectedTotal;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Methods\MethodResolver;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Results\ResultUrls;
use BiztechEG\Fawaterk\Support\Callbacks;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use BiztechEG\Fawaterk\Webhooks\WebhookType;
use BiztechEG\Fawaterk\Webhooks\WebhookUrls;
use DateTimeZone;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks an installation: config, routes, cache, tables, the reconcile
 * heartbeat, the ledger's open problems and (unless --offline) the Fawaterk
 * account. Secret values are never printed. Exits 1 when a check fails.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'fawaterk:doctor
        {--offline : Do not call Fawaterk}
        {--probe : Also POST to your webhook URLs, which must answer 401}';

    protected $description = 'Check the Fawaterk configuration, database, schedule and account';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        // Artisan keeps one instance of the command per application.
        $this->failures = 0;
        $this->warnings = 0;

        $this->section('Configuration');
        $credentials = $this->configuration();

        $this->section('Routes');
        $webhookUrls = $this->routes();

        $this->section('Cache');
        $this->cacheStore();

        $this->section('Database');
        $tables = $this->database();

        $this->section('Operations');
        $this->operations($credentials, $tables);

        if ($this->option('offline')) {
            $this->section('Fawaterk');
            $this->line('  skipped (--offline)');
        } elseif ($credentials !== null) {
            $this->section('Fawaterk');
            $this->account($credentials);
        }

        if ($this->option('probe')) {
            $this->section('Webhook probe');
            $this->probe($webhookUrls);
        }

        $this->newLine();
        $this->line(sprintf('%d failed, %d warning(s).', $this->failures, $this->warnings));

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function configuration(): ?Credentials
    {
        $credentials = null;

        try {
            $environment = Environment::fromConfig(config('fawaterk.environment'));
            $credentials = $this->laravel->make(AccountRepository::class)->get();
            $this->pass("Environment: {$environment->value} ({$environment->baseUrl()})");

            if ($environment === Environment::Staging && $this->laravel->environment('production')) {
                $this->warning('FAWATERK_ENV is staging on a production app: no real payments are taken.');
            }
        } catch (Throwable $e) {
            $this->failure($e->getMessage());
        }

        foreach (['client_id' => 'FAWATERK_CLIENT_ID', 'client_secret' => 'FAWATERK_CLIENT_SECRET', 'vendor_api_key' => 'FAWATERK_VENDOR_API_KEY'] as $key => $name) {
            $value = config('fawaterk.'.$key);
            is_string($value) && trim($value) !== '' ? $this->pass("{$name} is set") : $this->failure("{$name} is not set");
        }

        try {
            $this->pass('App URL: '.$this->laravel->make(WebhookUrls::class)->base());
        } catch (FawaterkException $e) {
            $this->failure($e->getMessage());
        }

        $commission = config('fawaterk.commission');
        in_array($commission, ExpectedTotal::MODES, true)
            ? $this->pass("Commission: {$commission}")
            : $this->failure('FAWATERK_COMMISSION must be merchant, customer or auto.');

        $fulfilment = config('fawaterk.fulfilment');
        in_array($fulfilment, ['manual', 'after_listeners'], true)
            ? $this->pass("Fulfilment: {$fulfilment}")
            : $this->failure('fawaterk.fulfilment must be manual or after_listeners.');

        try {
            new DateTimeZone((string) config('fawaterk.provider_timezone'));
        } catch (Throwable) {
            $this->failure('fawaterk.provider_timezone is not a valid timezone.');
        }

        $this->profiles();
        $this->alerts();

        return $credentials;
    }

    private function profiles(): void
    {
        $profiles = (array) config('fawaterk.profiles', []);
        $hosts = array_values(array_filter([
            ...array_map(fn ($host) => strtolower((string) $host), (array) config('fawaterk.return_url_hosts', [])),
            $this->laravel->make(WebhookUrls::class)->host(),
        ]));
        $valid = [];

        foreach ($profiles as $name => $options) {
            try {
                Profile::fromConfig((string) $name, is_array($options) ? $options : [], [], $hosts);
                $valid[] = (string) $name;
            } catch (FawaterkException $e) {
                $this->failure($e->getMessage());
            }
        }

        $default = (string) config('fawaterk.default_profile', 'hosted');

        if (! array_key_exists($default, $profiles)) {
            $this->failure("The default profile [{$default}] is not in fawaterk.profiles.");
        }

        if ($valid !== []) {
            $this->pass('Profiles: '.implode(', ', $valid)." (default: {$default})");
        }
    }

    private function alerts(): void
    {
        $addresses = array_filter(
            array_map('trim', explode(',', (string) config('fawaterk.notifications.mail'))),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        );

        if ($this->laravel->make(Callbacks::class)->notificationRouter !== null) {
            $this->pass('Anomaly mail: routed by Fawaterk::routeNotificationsUsing()');
        } elseif ($addresses !== []) {
            $this->pass('Anomaly mail: '.count($addresses).' address(es)'.(config('fawaterk.notifications.queue') ? ', queued' : ''));
        } else {
            $this->warning('No anomaly mail recipient: set FAWATERK_ALERT_MAIL.');
        }

        // The package's own mailer never listens to PaymentPaid, so this is the app.
        if ($this->laravel->make('events')->hasListeners(PaymentPaid::class)) {
            $this->pass('PaymentPaid has a listener');
        } elseif (config('fawaterk.fulfilment') === 'after_listeners') {
            $this->failure('Nothing listens to PaymentPaid: with fulfilment after_listeners every payment would be marked delivered with nothing delivered.');
        } else {
            $this->warning('Nothing listens to PaymentPaid: paid orders are never delivered (reconcile keeps re-sending it).');
        }
    }

    /**
     * @return array<string, string> webhook type => URL
     */
    private function routes(): array
    {
        $urls = [];
        $broken = false;

        try {
            $webhooks = $this->laravel->make(WebhookUrls::class);

            foreach (WebhookType::cases() as $type) {
                $url = $webhooks->for($type);

                if ($url !== null) {
                    $urls[$type->value] = $url;
                }
            }
        } catch (Throwable $e) {
            $broken = true;
            $this->failure('Webhook URLs: '.$e->getMessage());
        }

        if ($broken) {
            // Reported above.
        } elseif ($urls === []) {
            $this->warning('Route::fawaterkWebhooks() is not registered: webhooks cannot reach this app, and only reconcile settles payments.');
        } else {
            $this->pass('Webhooks are registered. Paste these into the Fawaterk dashboard:');
            $labels = ['paid' => 'Webhook', 'failed' => 'Failed', 'cancel' => 'Cancellation', 'refund' => 'Refund'];

            foreach ($urls as $type => $url) {
                $this->line(sprintf('       %-13s %s', ($labels[$type] ?? $type).':', $url));
            }
        }

        $results = $this->laravel->make(ResultUrls::class);

        if (! $results->registered()) {
            $this->warning('Route::fawaterk() is not registered: payers return only where your profiles\' return_urls send them.');

            return $urls;
        }

        try {
            $results->for('00000000-0000-4000-8000-000000000000');
            $key = config('fawaterk.results.key');
            $this->pass('Result pages are registered (signing key: '.(is_string($key) && $key !== '' ? 'FAWATERK_RESULT_KEY' : 'derived from APP_KEY').')');
        } catch (Throwable $e) {
            $this->failure('Result URLs: '.$e->getMessage());
        }

        return $urls;
    }

    private function cacheStore(): void
    {
        $name = config('fawaterk.cache_store') ?: config('cache.default');

        try {
            /** @var CacheRepository $cache */
            $cache = $this->laravel->make('cache')->store(config('fawaterk.cache_store'));
            $store = $cache->getStore();
        } catch (Throwable $e) {
            $this->failure("Cache store [{$name}]: ".$e->getMessage());

            return;
        }

        match (true) {
            $store instanceof ArrayStore, $store instanceof NullStore, ! $store instanceof LockProvider => $this->failure("Cache store [{$name}] cannot lock across processes: checkouts are refused. Use redis, database, memcached or dynamodb (FAWATERK_CACHE_STORE)."),
            $store instanceof FileStore => $this->warning("Cache store [{$name}] is file: its locks work on one server only."),
            default => $this->pass("Cache store: {$name}"),
        };
    }

    private function database(): bool
    {
        $connection = Ledger::connection();

        try {
            $schema = Schema::connection($connection);
            $missing = array_values(array_filter(
                [Ledger::table('payments'), Ledger::table('webhook_events')],
                fn (string $table) => ! $schema->hasTable($table),
            ));
        } catch (Throwable $e) {
            $this->failure('The ledger database cannot be reached: '.$e->getMessage());

            return false;
        }

        if ($missing !== []) {
            $this->failure('Missing tables: '.implode(', ', $missing).'. Run php artisan migrate.');

            return false;
        }

        $this->pass('Tables: '.Ledger::table('payments').', '.Ledger::table('webhook_events').' (connection: '.($connection ?? 'default').')');
        $this->keyType($schema);

        return true;
    }

    private function keyType(mixed $schema): void
    {
        $expected = (string) config('fawaterk.ledger.payable_key_type', 'int');

        $type = $this->columnType(Ledger::table('payments'), 'payable_id') ?? (function () use ($schema) {
            try {
                return strtolower((string) $schema->getColumnType(Ledger::table('payments'), 'payable_id'));
            } catch (Throwable) {
                return null; // Laravel 9 needs doctrine/dbal for this
            }
        })();

        if ($type === null || $type === '') {
            $this->line('  (the payable_id column type cannot be read here)');

            return;
        }

        $isInteger = str_contains($type, 'int');

        if (($expected === 'int') === $isInteger) {
            $this->pass("payable_id matches payable_key_type ({$expected})");
        } else {
            $this->failure("payable_id is {$type}, but fawaterk.ledger.payable_key_type is {$expected}.");
        }
    }

    /**
     * The column's type from the database itself, so it works on every
     * Laravel version without doctrine/dbal.
     */
    private function columnType(string $table, string $column): ?string
    {
        try {
            $connection = Ledger::newPayment()->getConnection();
            $table = $connection->getTablePrefix().$table;

            $type = match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $connection->selectOne('SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column])?->type,
                'pgsql' => $connection->selectOne('SELECT data_type AS type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$table, $column])?->type,
                'sqlite' => collect($connection->select('PRAGMA table_info('.$connection->getQueryGrammar()->wrapTable($table).')'))->firstWhere('name', $column)?->type,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return is_string($type) ? strtolower($type) : null;
    }

    private function operations(?Credentials $credentials, bool $tables): void
    {
        if ($credentials === null) {
            return;
        }

        $cache = $this->laravel->make('cache')->store(config('fawaterk.cache_store'));

        try {
            $beat = $cache->get($this->laravel->make(Reconciler::class)->heartbeatKey());
            $rejected = (int) $cache->get(WebhookController::rejectedCountKey($credentials->account, $credentials->environment->value), 0);
        } catch (Throwable $e) {
            $this->failure('The cache cannot be read: '.$e->getMessage());

            return;
        }

        if (! is_numeric($beat)) {
            $this->warning('fawaterk:reconcile has never run. Schedule it every five minutes.');
        } else {
            $minutes = intdiv(max(0, Carbon::now()->getTimestamp() - (int) $beat), 60);
            $minutes > 30
                ? $this->failure("fawaterk:reconcile last ran {$minutes} minutes ago: is the scheduler running?")
                : $this->pass("fawaterk:reconcile last ran {$minutes} minute(s) ago");
        }

        $rejected >= 50
            ? $this->warning("{$rejected} webhooks were rejected today (bad signature, malformed or too large). Check the vendor key, or someone is probing.")
            : $this->pass("Rejected webhooks today: {$rejected}");

        if ($tables) {
            $this->ledger($credentials);
        }
    }

    /**
     * What the anomaly mail reported, for when the mail did not arrive:
     * blocking flags and unconfirmed refunds of the last 30 days, paid checkouts
     * this app did not create, and payments paid but still not delivered.
     *
     * Settled anomalies are warnings: someone may already have handled them,
     * and there is no way to mark that in v1.0. An undelivered payment fails.
     */
    private function ledger(Credentials $credentials): void
    {
        $rows = fn () => Ledger::newPayment()->newQuery()
            ->where('account', $credentials->account)
            ->where('environment', $credentials->environment->value);

        $flagged = ['count' => 0, 'shown' => []];
        $refunds = ['count' => 0, 'shown' => []];
        $rows()->whereNotNull('flags')
            ->where('updated_at', '>=', Carbon::now()->subDays(30))
            ->lazyById(200)
            ->each(function (FawaterkPayment $payment) use (&$flagged, &$refunds) {
                if ($payment->hasBlockingFlag()) {
                    $flagged['count']++;
                    $flagged['shown'] = array_slice([...$flagged['shown'], $payment], 0, 10);
                }

                if ($payment->hasFlag(Flag::RefundUnverified)) {
                    $refunds['count']++;
                    $refunds['shown'] = array_slice([...$refunds['shown'], $payment], 0, 10);
                }
            });

        if ($flagged['count'] === 0) {
            $this->pass('No payment with a blocking flag in the last 30 days');
        } else {
            $this->warning($flagged['count'].' payment(s) with a blocking flag in the last 30 days (check them in the Fawaterk dashboard):');
            $this->rows($flagged['shown'], $flagged['count']);
        }

        if ($refunds['count'] > 0) {
            $this->warning($refunds['count'].' payment(s) with a refund the refund list did not confirm (last 30 days):');
            $this->rows($refunds['shown'], $refunds['count']);
        }

        $this->unknownPaid($credentials);
        $this->misroutedRefunds($credentials);

        // Known since: reconcile may find a payment long after it was paid.
        $since = Carbon::now()->subMinutes(max(1, (int) config('fawaterk.reconcile.alert_after_minutes', 30)));
        $undelivered = ['count' => 0, 'shown' => []];
        $rows()->where('status', PaymentStatus::Paid->value)
            ->whereNull('fulfilled_at')
            ->where('paid_at', '<', $since)
            ->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $since))
            ->lazyById(200)
            ->each(function (FawaterkPayment $payment) use (&$undelivered) {
                if (! $payment->hasBlockingFlag()) {
                    $undelivered['count']++;
                    $undelivered['shown'] = array_slice([...$undelivered['shown'], $payment], 0, 10);
                }
            });

        if ($undelivered['count'] === 0) {
            $this->pass('No paid payment waiting for delivery past the alert time');
        } else {
            $this->failure($undelivered['count'].' paid payment(s) not delivered yet:');
            $this->rows($undelivered['shown'], $undelivered['count']);
        }

        $late = $rows()->whereIn('status', array_map(fn (PaymentStatus $status) => $status->value, PaymentStatus::open()))
            ->where('next_check_at', '<', Carbon::now()->subMinutes(30))
            ->count();

        if ($late > 0) {
            $this->warning("{$late} open payment(s) are overdue for a re-check: reconcile is behind or not running.");
        }
    }

    /**
     * Signed refund webhooks that reached another webhook URL (RefundWebhookMisrouted).
     */
    private function misroutedRefunds(Credentials $credentials): void
    {
        $events = WebhookEvent::query()
            ->where('account', $credentials->account)
            ->where('environment', $credentials->environment->value)
            ->where('outcome', 'misrouted')
            ->where('created_at', '>=', Carbon::now()->subDays(30));
        $count = (clone $events)->count();

        if ($count === 0) {
            return;
        }

        $types = (clone $events)->distinct()->orderBy('type')->pluck('type')->implode(', ');
        $this->warning($count." refund webhook(s) reached another webhook URL in the last 30 days ({$types}): the Fawaterk dashboard's Refund field holds the wrong URL. Reconcile counts these refunds from the refund list once a day.");
    }

    /**
     * Signed paid webhooks for checkouts this app did not create (UnknownPaymentPaid).
     */
    private function unknownPaid(Credentials $credentials): void
    {
        $events = fn () => WebhookEvent::query()
            ->where('account', $credentials->account)
            ->where('environment', $credentials->environment->value)
            ->where('outcome', 'unknown_payment')
            // Only the ones Fawaterk reported paid: an unpaid or missing one has the same outcome but no key.
            ->where('dedupe_key', 'like', 'unknown_paid:%')
            ->where('created_at', '>=', Carbon::now()->subDays(30));
        $count = $events()->count();

        if ($count === 0) {
            return;
        }

        $this->warning($count.' paid checkout(s) this app did not create (last 30 days; another integration on the account?):');

        foreach ($events()->orderByDesc('id')->limit(10)->pluck('intent_key') as $intent) {
            $this->line('       '.$intent);
        }
    }

    /**
     * @param  array<int, FawaterkPayment>  $payments  the first rows (at most 10 are shown)
     */
    private function rows(array $payments, ?int $total = null): void
    {
        $total ??= count($payments);
        foreach (array_slice($payments, 0, 10) as $payment) {
            $flags = array_keys($payment->flags ?? []);
            $this->line(sprintf(
                '       %s  %s #%s  %s%s',
                $payment->uuid,
                $payment->payable_type,
                $payment->payable_id,
                $payment->paid_at?->format('Y-m-d H:i') ?? '',
                $flags === [] ? '' : '  ['.implode(', ', $flags).']',
            ));
        }

        if ($total > min(10, count($payments))) {
            $this->line('       … and '.($total - min(10, count($payments))).' more');
        }
    }

    private function account(Credentials $credentials): void
    {
        $client = $this->laravel->make(FawaterkClient::class);

        if ($client instanceof FawaterkFake) {
            $this->warning('Fawaterk::fake() is active: the account was not checked.');
        } else {
            try {
                $this->laravel->make(AccessTokenProvider::class)->token($credentials, 10);
                $this->pass('OAuth token: received');
            } catch (AuthenticationException $e) {
                $this->failure('Fawaterk refused the OAuth client (check FAWATERK_CLIENT_ID, FAWATERK_CLIENT_SECRET and FAWATERK_ENV): '.$e->getMessage());

                return;
            } catch (FawaterkException $e) {
                $this->failure('No OAuth token: '.$e->getMessage());

                return;
            }

            $this->vendorKey($credentials);
        }

        try {
            $methods = $client->getPaymentMethods();
        } catch (FawaterkException $e) {
            $this->failure('The payment methods cannot be read: '.$e->getMessage());

            return;
        }

        $commission = (string) config('fawaterk.commission', 'merchant');
        $this->table(['id', 'name_en', 'redirect', 'commission on customer'], array_map(fn (PaymentMethod $method) => [
            $method->id,
            $method->nameEn,
            $method->redirect ? 'true (link)' : 'false (code)',
            match ($method->commissionOnCustomer) {
                true => 'yes',
                false => 'no',
                null => '?',
            },
        ], $methods));

        foreach ($methods as $method) {
            if ($commission === 'merchant' && $method->commissionOnCustomer === true) {
                $this->warning("{$method->nameEn} charges its commission to the customer: with FAWATERK_COMMISSION=merchant its payments are flagged amount_mismatch. Use auto, or change it in Fawaterk.");
            }

            if ($commission === 'customer' && $method->commissionOnCustomer === false) {
                $this->warning("{$method->nameEn} charges its commission to you: with FAWATERK_COMMISSION=customer its payments are flagged amount_mismatch. Use auto.");
            }
        }

        $this->configuredMethods();
    }

    /**
     * The vendor API key signs every webhook, and nothing else here calls Fawaterk with it, so a wrong key only shows
     * when every webhook is refused as bad_signature. Fawaterk's read-only v2 method list accepts or refuses it.
     */
    private function vendorKey(Credentials $credentials): void
    {
        /** @var Factory $http */
        $http = $this->laravel->make('fawaterk.http');

        try {
            $response = $http->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->withToken($credentials->vendorApiKey())
                ->timeout(10)
                ->connectTimeout(min($credentials->connectTimeout, 10))
                ->get($credentials->url('/api/v2/getPaymentmethods'));
        } catch (Throwable) {
            $this->warning('The vendor API key could not be checked: Fawaterk could not be reached.');

            return;
        }

        if ($response->successful() && $response->json('status') === 'success') {
            $this->pass('Vendor API key: accepted (it signs the webhooks)');
        } elseif (in_array($response->status(), [401, 403], true) || $response->json('message.token') !== null) {
            $this->failure('Fawaterk refused FAWATERK_VENDOR_API_KEY: every webhook would be refused as bad_signature. Copy the API key from the integration page of the dashboard; it is not the OAuth client.');
        } else {
            $this->warning("The vendor API key could not be checked: Fawaterk answered {$response->status()}.");
        }
    }

    private function configuredMethods(): void
    {
        $resolver = $this->laravel->make(MethodResolver::class);
        $resolver->forget();

        foreach (array_keys((array) config('fawaterk.methods', [])) as $name) {
            try {
                $method = $resolver->resolve((string) $name);
            } catch (FawaterkException $e) {
                $this->failure("Method [{$name}]: ".$e->getMessage());

                continue;
            }

            $gives = $method->redirect ? 'a link (card-preselected)' : 'a reference code';

            if ($method->redirect && preg_match('/fawry|aman|masary|basta/i', $method->nameEn)) {
                $this->failure("Method [{$name}] is {$method->nameEn} #{$method->id} with redirect=true: Fawaterk returns a link, not a code. Ask Fawaterk to switch it to direct payment.");
            } else {
                $this->pass("Method [{$name}] → {$method->nameEn} #{$method->id}: {$gives}");
            }
        }
    }

    /**
     * @param  array<string, string>  $urls
     */
    private function probe(array $urls): void
    {
        if ($urls === []) {
            $this->warning('No webhook route to probe.');

            return;
        }

        /** @var Factory $http */
        $http = $this->laravel->make('fawaterk.http');

        foreach ($urls as $type => $url) {
            try {
                $status = $http->withoutRedirecting()->timeout(5)->connectTimeout(5)->asJson()->post($url, [])->status();
            } catch (Throwable) {
                $this->warning("{$type}: {$url} could not be reached from this server (some hosts block calls to their own domain).");

                continue;
            }

            $status === 401
                ? $this->pass("{$type}: 401 as expected")
                : $this->failure("{$type}: {$url} answered {$status}, expected 401. Check the route, a firewall or a redirect.");
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment>");
    }

    private function pass(string $message): void
    {
        $this->line("  <info>OK</info>    {$message}");
    }

    private function warning(string $message): void
    {
        $this->warnings++;
        $this->line("  <comment>WARN</comment>  {$message}");
    }

    private function failure(string $message): void
    {
        $this->failures++;
        $this->line("  <error>FAIL</error>  {$message}");
    }
}

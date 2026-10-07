<?php

namespace BiztechEG\Fawaterk;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Auth\AccessTokenProvider;
use BiztechEG\Fawaterk\Checkout\CheckoutService;
use BiztechEG\Fawaterk\Console\DoctorCommand;
use BiztechEG\Fawaterk\Console\InstallCommand;
use BiztechEG\Fawaterk\Console\ReconcileCommand;
use BiztechEG\Fawaterk\Console\SimulateCommand;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentDataParser;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Http\Client;
use BiztechEG\Fawaterk\Http\Controllers\ResultController;
use BiztechEG\Fawaterk\Http\Controllers\WebhookController;
use BiztechEG\Fawaterk\Ledger\ExpectedTotal;
use BiztechEG\Fawaterk\Ledger\PaymentRecorder;
use BiztechEG\Fawaterk\Methods\MethodResolver;
use BiztechEG\Fawaterk\Notifications\AnomalyMailer;
use BiztechEG\Fawaterk\Notifications\PendingAlerts;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Refunds\RefundVerifier;
use BiztechEG\Fawaterk\Refunds\RefundWatcher;
use BiztechEG\Fawaterk\Results\ResultSigner;
use BiztechEG\Fawaterk\Results\ResultUrls;
use BiztechEG\Fawaterk\Support\Callbacks;
use BiztechEG\Fawaterk\Support\SafeLog;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Webhooks\WebhookProcessor;
use BiztechEG\Fawaterk\Webhooks\WebhookUrls;
use DateTimeZone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class FawaterkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fawaterk.php', 'fawaterk');

        // Everything below is resolved lazily: nothing reads a secret or calls
        // Fawaterk until the app actually uses the package.

        $this->app->singleton(AccountRepository::class, fn (Application $app) => new AccountRepository(
            (array) $app->make('config')->get('fawaterk', [])
        ));

        // A private HTTP factory with no event dispatcher: framework HTTP events
        // (and tools that record them) never see Fawaterk secrets or tokens.
        $this->app->singleton('fawaterk.http', fn () => new Factory);

        $this->app->singleton(AccessTokenProvider::class, fn (Application $app) => new AccessTokenProvider(
            $app->make('fawaterk.http'),
            $this->cache($app),
            $app->make('encrypter'),
        ));

        $this->app->singleton(PaymentDataParser::class, fn (Application $app) => new PaymentDataParser(
            $this->timezone($app)
        ));

        $this->app->singleton(FawaterkClient::class, fn (Application $app) => new Client(
            $app->make('fawaterk.http'),
            $app->make(AccessTokenProvider::class),
            $app->make(AccountRepository::class)->get(),
            $app->make(PaymentDataParser::class),
        ));

        // Not a singleton: it must pick up Fawaterk::fake() swaps of the client.
        // A fake brings its own cache, so fake methods never reach the real one.
        $this->app->bind(MethodResolver::class, function (Application $app) {
            $client = $app->make(FawaterkClient::class);

            return new MethodResolver(
                $client,
                $client instanceof FawaterkFake ? $client->cache() : $this->cache($app),
                $app->make(AccountRepository::class)->get(),
                (array) $app->make('config')->get('fawaterk.methods', []),
                (int) $app->make('config')->get('fawaterk.methods_cache_ttl', 600),
            );
        });

        $this->app->singleton(SafeLog::class, function (Application $app) {
            $channel = $app->make('config')->get('fawaterk.log_channel');

            return new SafeLog(is_string($channel) && $channel !== '' ? $app->make('log')->channel($channel) : null);
        });

        // The ledger, webhook and checkout services resolve the client when they
        // use it, so Fawaterk::fake() reaches them too.
        $this->app->bind(PaymentRecorder::class, fn (Application $app) => new PaymentRecorder(
            $app->make('events'),
            new ExpectedTotal((string) $this->config($app, 'commission', 'merchant'), fn () => $app->make(MethodResolver::class)),
            $this->timezone($app),
            $this->fulfilment($app),
            (int) $this->config($app, 'reconcile.first_redispatch_minutes', 10),
        ));

        $this->app->bind(RefundVerifier::class, fn (Application $app) => new RefundVerifier(
            $this->client($app),
            $app->make(PaymentRecorder::class),
            (int) $this->config($app, 'reconcile.refund_scan_pages', 5),
        ));

        $this->app->bind(RefundWatcher::class, fn (Application $app) => new RefundWatcher(
            $app->make(RefundVerifier::class),
            $app->make(PaymentRecorder::class),
            $this->cache($app),
            (array) $app->make('config')->get('fawaterk', []),
        ));

        $this->app->bind(WebhookProcessor::class, fn (Application $app) => new WebhookProcessor(
            $this->client($app),
            $app->make(PaymentRecorder::class),
            $app->make(RefundWatcher::class),
            $this->cache($app),
            $app->make('events'),
            (array) $app->make('config')->get('fawaterk', []),
        ));

        $this->app->bind(WebhookController::class, fn (Application $app) => new WebhookController(
            $app->make(AccountRepository::class),
            $app->make(WebhookProcessor::class),
            $app->make(SafeLog::class),
            $this->cache($app),
        ));

        $this->app->bind(WebhookUrls::class, fn (Application $app) => new WebhookUrls(
            $app->make('router'),
            $this->appUrl($app),
        ));

        $this->app->singleton(Callbacks::class);
        $this->app->singleton(PendingAlerts::class);

        $this->app->bind(ResultSigner::class, fn (Application $app) => new ResultSigner(
            self::stringOrNull($this->config($app, 'results.key')),
            self::stringOrNull($app->make('config')->get('app.key')),
        ));

        $this->app->bind(ResultUrls::class, fn (Application $app) => new ResultUrls(
            $app->make('router'),
            $app->make(WebhookUrls::class),
            $app->make(ResultSigner::class),
            (int) $this->config($app, 'results.valid_days', 30),
        ));

        $this->app->bind(CheckoutService::class, fn (Application $app) => new CheckoutService(
            $this->client($app),
            fn () => $app->make(MethodResolver::class),
            $app->make(PaymentRecorder::class),
            $this->cache($app),
            $app->make(AccountRepository::class)->get(),
            $app->make(WebhookUrls::class),
            $app->make(ResultUrls::class),
            (array) $app->make('config')->get('fawaterk', []),
        ));

        $this->app->bind(Reconciler::class, fn (Application $app) => new Reconciler(
            $this->client($app),
            $app->make(PaymentRecorder::class),
            $app->make(RefundWatcher::class),
            $this->cache($app),
            $app->make(AccountRepository::class)->get(),
            $app->make(SafeLog::class),
            (array) $app->make('config')->get('fawaterk', []),
        ));

        $this->app->singleton(Fawaterk::class, fn (Application $app) => new Fawaterk($app));
    }

    public function boot(): void
    {
        // No routes are registered here. The app opts in explicitly with the
        // Route::fawaterkWebhooks() macro (and Route::fawaterk() for the result
        // pages), so nothing is exposed by installing the package.
        $this->registerRouteMacros();

        RateLimiter::for('fawaterk-webhooks', fn (Request $request) => Limit::perMinute(
            max(1, (int) $this->config($this->app, 'webhooks.rate_limit', 120))
        )->by((string) $request->ip()));

        RateLimiter::for('fawaterk-results', fn (Request $request) => Limit::perMinute(
            max(1, (int) $this->config($this->app, 'results.rate_limit', 60))
        )->by((string) $request->ip()));

        // Anomaly mail. It mails only the events listed in
        // fawaterk.notifications.events, and nothing until FAWATERK_ALERT_MAIL
        // or Fawaterk::routeNotificationsUsing() gives it a recipient.
        $this->app->make('events')->listen(AnomalyMailer::EVENTS, [AnomalyMailer::class, 'handle']);

        // The mail goes out after the response: when the app terminates,
        // and after each queue job for long-running workers.
        $flush = fn () => $this->app->make(PendingAlerts::class)->flush();
        $this->app->terminating($flush);
        $this->app->make('events')->listen([JobProcessed::class, JobFailed::class], $flush);

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'fawaterk');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'fawaterk');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, DoctorCommand::class, ReconcileCommand::class, SimulateCommand::class]);

            $this->publishes([
                __DIR__.'/../config/fawaterk.php' => config_path('fawaterk.php'),
            ], 'fawaterk-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_fawaterk_tables.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_fawaterk_tables.php'),
            ], 'fawaterk-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/fawaterk'),
            ], 'fawaterk-views');

            $this->publishes([
                __DIR__.'/../resources/lang' => lang_path('vendor/fawaterk'),
            ], 'fawaterk-lang');
        }
    }

    private function registerRouteMacros(): void
    {
        /**
         * POST {prefix}/{paid_json|failed_json|cancel_json|refund_json}[/{account}]
         *
         * Point Fawaterk's dashboard webhook URLs here. The route is CSRF-free
         * and throttled by the "fawaterk-webhooks" limiter. Its name defaults
         * to "fawaterk.webhooks" (any name or group name prefix works).
         *
         * @param  array{domain?: string, middleware?: list<string>, name?: string}  $options
         */
        Router::macro('fawaterkWebhooks', function (string $prefix = 'fawaterk/webhooks', array $options = []): Route {
            /** @var Router $this */
            $route = $this->post(trim($prefix, '/').'/{type}/{account?}', WebhookController::class)
                ->where('type', '(paid|failed|cancel|refund)(_json)?')
                ->where('account', '[a-z0-9_-]{1,32}')
                ->name($options['name'] ?? WebhookUrls::ROUTE_NAME)
                ->middleware(array_merge(['throttle:fawaterk-webhooks'], $options['middleware'] ?? []))
                ->withoutMiddleware(array_values(array_filter([
                    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
                ], 'class_exists')));

            if (isset($options['domain'])) {
                $route->domain($options['domain']);
            }

            return $route;
        });

        /**
         * GET {prefix}/result/{payment}/{expires}/{signature}
         *
         * The page Fawaterk sends the payer back to (success, fail, pending and
         * back URLs). Once registered, checkouts send it to Fawaterk unless a
         * profile sets its own return_urls. A POST is answered with a redirect
         * to the same URL, so the route is CSRF-free. Throttled by the
         * "fawaterk-results" limiter; the name defaults to "fawaterk.result".
         *
         * @param  array{domain?: string, middleware?: list<string>, name?: string}  $options
         */
        Router::macro('fawaterk', function (string $prefix = 'fawaterk', array $options = []): Route {
            /** @var Router $this */
            $route = $this->match(['GET', 'POST'], trim($prefix, '/').'/result/{'.ResultUrls::PAYMENT.'}/{'.ResultUrls::EXPIRES.'}/{'.ResultUrls::SIGNATURE.'}', ResultController::class)
                ->name($options['name'] ?? ResultUrls::ROUTE_NAME)
                ->middleware(array_merge(['throttle:fawaterk-results'], $options['middleware'] ?? []))
                ->withoutMiddleware(array_values(array_filter([
                    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
                ], 'class_exists')));

            if (isset($options['domain'])) {
                $route->domain($options['domain']);
            }

            return $route;
        });
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return \Closure(): FawaterkClient
     */
    private function client(Application $app): \Closure
    {
        return fn () => $app->make(FawaterkClient::class);
    }

    private function cache(Application $app): CacheRepository
    {
        return $app->make('cache')->store($app->make('config')->get('fawaterk.cache_store'));
    }

    private function config(Application $app, string $key, mixed $default = null): mixed
    {
        return $app->make('config')->get('fawaterk.'.$key, $default);
    }

    private function appUrl(Application $app): ?string
    {
        $url = $this->config($app, 'app_url') ?: $app->make('config')->get('app.url');

        return is_string($url) ? $url : null;
    }

    private function fulfilment(Application $app): string
    {
        $mode = (string) $this->config($app, 'fulfilment', 'manual');

        if (! in_array($mode, PaymentRecorder::FULFILMENT_MODES, true)) {
            throw new ConfigurationException('fawaterk.fulfilment must be manual or after_listeners.');
        }

        return $mode;
    }

    private function timezone(Application $app): DateTimeZone
    {
        $name = (string) $this->config($app, 'provider_timezone', 'Africa/Cairo');

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new ConfigurationException("fawaterk.provider_timezone [{$name}] is not a valid timezone.");
        }
    }
}

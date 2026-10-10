<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Events\PaymentPaid;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Http\Controllers\WebhookController;
use BiztechEG\Fawaterk\Ledger\FawaterkPayment;
use BiztechEG\Fawaterk\Ledger\Flag;
use BiztechEG\Fawaterk\Ledger\Ledger;
use BiztechEG\Fawaterk\Ledger\PaymentStatus;
use BiztechEG\Fawaterk\Reconcile\Reconciler;
use BiztechEG\Fawaterk\Tests\Fixtures\RealClient;
use BiztechEG\Fawaterk\Tests\LedgerTestCase;
use BiztechEG\Fawaterk\Webhooks\WebhookEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CommandsTest extends LedgerTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('fawaterk.client_id', 'doctor-client-id-value');
        $app['config']->set('fawaterk.client_secret', 'doctor-client-secret-value');
        $app['config']->set('fawaterk.vendor_api_key', 'doctor-vendor-key-value');
        $app['config']->set('fawaterk.notifications.mail', 'ops@shop.test');
        $app['config']->set('fawaterk.commission', 'auto');
        // A store that locks across processes, like a real install.
        $app['config']->set('cache.stores.locking', ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks', 'connection' => 'testing']);
        $app['config']->set('fawaterk.cache_store', 'locking');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        $this->app['router']->fawaterk();
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->heartbeat(Carbon::now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach ([config_path('fawaterk.php'), ...(glob(database_path('migrations/*_create_fawaterk_tables.php')) ?: [])] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_a_healthy_install_passes_and_never_prints_secrets(): void
    {
        [$code, $output] = $this->doctor();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('0 failed', $output);
        $this->assertStringContainsString('FAWATERK_CLIENT_SECRET is set', $output);
        $this->assertStringContainsString('https://shop.test/fawaterk/webhooks/paid_json', $output);
        $this->assertStringContainsString('https://shop.test/fawaterk/webhooks/refund_json', $output);
        $this->assertStringContainsString('Result pages are registered (signing key: derived from APP_KEY)', $output);
        $this->assertStringContainsString('Cache store: locking', $output);
        $this->assertStringContainsString('fawaterk:reconcile last ran 0 minute(s) ago', $output);

        foreach (['doctor-client-id-value', 'doctor-client-secret-value', 'doctor-vendor-key-value', (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_missing_secrets_and_a_bad_app_url_fail(): void
    {
        config()->set('fawaterk.client_secret', null);
        config()->set('app.url', 'http://shop.test');

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  FAWATERK_CLIENT_SECRET is not set', $output);
        $this->assertStringContainsString('must be an https origin', $output);
    }

    public function test_a_cache_store_that_cannot_lock_fails_and_file_warns(): void
    {
        config()->set('fawaterk.cache_store', 'array');
        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('cannot lock across processes', $output);

        config()->set('cache.stores.file', ['driver' => 'file', 'path' => sys_get_temp_dir().'/fawaterk-doctor-cache']);
        config()->set('fawaterk.cache_store', 'file');
        $this->heartbeat(Carbon::now());
        [$code, $output] = $this->doctor();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('locks work on one server only', $output);
    }

    public function test_the_reconcile_heartbeat(): void
    {
        Cache::store('locking')->forget($this->app->make(Reconciler::class)->heartbeatKey());
        [$code, $output] = $this->doctor();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('WARN  fawaterk:reconcile has never run', $output);

        $this->heartbeat(Carbon::now()->subMinutes(45));
        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('last ran 45 minutes ago: is the scheduler running?', $output);
    }

    public function test_it_counts_todays_rejected_webhooks(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->call('POST', '/fawaterk/webhooks/paid_json', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401);
        }

        $this->assertSame(3, (int) Cache::store('locking')->get(WebhookController::rejectedCountKey('default', 'staging')));
        $this->assertStringContainsString('Rejected webhooks today: 3', $this->doctor()[1]);
    }

    public function test_payments_that_need_a_person_are_listed(): void
    {
        $flagged = $this->paidRow(['flags' => [Flag::PaidTwice->value => 'now']]);
        $undelivered = $this->paidRow(['paid_at' => Carbon::now()->subHour()]);
        $delivered = $this->paidRow(['paid_at' => Carbon::now()->subHour(), 'fulfilled_at' => Carbon::now()]);
        $recent = $this->paidRow(['paid_at' => Carbon::now()->subMinutes(5)]);
        $old = $this->paidRow(['flags' => [Flag::AmountMismatch->value => 'then'], 'paid_at' => Carbon::now()->subDays(40), 'updated_at' => Carbon::now()->subDays(40)]);

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('1 payment(s) with a blocking flag in the last 30 days', $output);
        $this->assertStringContainsString($flagged->uuid, $output);
        $this->assertStringContainsString('[paid_twice]', $output);
        $this->assertStringContainsString('1 paid payment(s) not delivered yet', $output);
        $this->assertStringContainsString($undelivered->uuid, $output);

        foreach ([$delivered, $recent, $old] as $quiet) {
            $this->assertStringNotContainsString($quiet->uuid, $output);
        }
    }

    public function test_missing_tables_and_a_wrong_key_type_fail(): void
    {
        config()->set('fawaterk.ledger.payable_key_type', 'uuid');
        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('payable_id is integer, but fawaterk.ledger.payable_key_type is uuid', $output);

        Schema::drop(Ledger::table('webhook_events'));
        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Missing tables: fawaterk_webhook_events. Run php artisan migrate.', $output);
    }

    public function test_the_account_check_shows_the_method_table_and_resolves_the_profiles_methods(): void
    {
        config()->set('fawaterk.commission', 'merchant');

        [$code, $output] = $this->doctor(online: true);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Fawaterk::fake() is active', $output);
        $this->assertMatchesRegularExpression('/\|\s*3\s*\|\s*Fawry\s*\|\s*false \(code\)\s*\|\s*yes/', $output);
        $this->assertStringContainsString('Fawry charges its commission to the customer', $output);
        $this->assertStringContainsString('Method [fawry] → Fawry #3: a reference code', $output);
        $this->assertStringContainsString('Method [card] → Visa-Mastercard #2: a link (card-preselected)', $output);
    }

    public function test_the_vendor_api_key_is_checked_with_fawaterk(): void
    {
        $this->app->instance(FawaterkClient::class, new RealClient($this->fake));
        $seen = [];
        $this->fakeFawaterkHttp(function ($request) use (&$seen) {
            $seen[] = $request;

            return str_ends_with($request->url(), '/oauth/token')
                ? Factory::response(['token_type' => 'Bearer', 'expires_in' => 31536000, 'access_token' => 'tok-1'])
                : Factory::response(['status' => 'success', 'data' => [['paymentId' => 2, 'name_en' => 'Visa-Mastercard']]]);
        });

        [$code, $output] = $this->doctor(online: true);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Vendor API key: accepted', $output);
        $check = collect($seen)->first(fn ($request) => str_contains($request->url(), '/api/v2/getPaymentmethods'));
        $this->assertNotNull($check);
        $this->assertSame('GET', $check->method());
        $this->assertSame(['Bearer doctor-vendor-key-value'], $check->header('Authorization'));
        $this->assertStringNotContainsString('doctor-vendor-key-value', $output);
    }

    public function test_a_vendor_api_key_fawaterk_refuses_fails_the_doctor(): void
    {
        // Fawaterk's answer to a wrong key: the same as to a made-up one.
        $this->app->instance(FawaterkClient::class, new RealClient($this->fake));
        $this->fakeFawaterkHttp(fn ($request) => str_ends_with($request->url(), '/oauth/token')
            ? Factory::response(['token_type' => 'Bearer', 'expires_in' => 31536000, 'access_token' => 'tok-1'])
            : Factory::response(['status' => 'error', 'message' => ['token' => ['Invalid Token or inactive vendor.']]], 400));

        [$code, $output] = $this->doctor(online: true);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Fawaterk refused FAWATERK_VENDOR_API_KEY: every webhook would be refused as bad_signature', $output);
    }

    public function test_a_vendor_api_key_check_that_cannot_decide_only_warns(): void
    {
        $this->app->instance(FawaterkClient::class, new RealClient($this->fake));
        $answers = [
            fn () => throw new ConnectionException('down'),
            fn () => Factory::response(['status' => 'error', 'message' => ['content-type' => ['The content-type field is required.']]], 400),
            fn () => Factory::response('<html>Bad gateway</html>', 502),
        ];

        foreach ($answers as $answer) {
            $this->fakeFawaterkHttp(fn ($request) => str_ends_with($request->url(), '/oauth/token')
                ? Factory::response(['token_type' => 'Bearer', 'expires_in' => 31536000, 'access_token' => 'tok-1'])
                : $answer());

            [$code, $output] = $this->doctor(online: true);

            $this->assertSame(0, $code, $output);
            $this->assertStringContainsString('The vendor API key could not be checked', $output);
        }
    }

    public function test_a_code_method_in_link_mode_fails(): void
    {
        $this->fake->setPaymentMethods(new PaymentMethod(2, 'Visa-Mastercard', null, true, false), new PaymentMethod(3, 'Fawry', null, true, false));

        [$code, $output] = $this->doctor(online: true);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('with redirect=true: Fawaterk returns a link, not a code', $output);
    }

    public function test_a_basata_method_in_link_mode_fails(): void
    {
        config()->set('fawaterk.methods.basata', ['name_en' => 'Basata']);
        $this->fake->setPaymentMethods(
            new PaymentMethod(2, 'Visa-Mastercard', null, true, false),
            new PaymentMethod(3, 'Fawry', null, false, false),
            new PaymentMethod(14, 'Basata', null, true, false),
        );

        [$code, $output] = $this->doctor(online: true);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Basata #14 with redirect=true: Fawaterk returns a link, not a code', $output);
    }

    public function test_an_unknown_method_name_fails(): void
    {
        config()->set('fawaterk.methods.aman', ['name_en' => 'Aman']);

        [$code, $output] = $this->doctor(online: true);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Method [aman]', $output);
    }

    public function test_offline_makes_no_call_to_fawaterk(): void
    {
        $calls = $this->fake->paymentMethodCalls();

        $this->doctor();

        $this->assertSame($calls, $this->fake->paymentMethodCalls());
    }

    public function test_the_probe_expects_401_from_every_webhook_url(): void
    {
        $seen = [];
        $this->fakeFawaterkHttp(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Factory::response('', str_contains($request->url(), 'refund') ? 200 : 401);
        });

        [$code, $output] = $this->doctor(probe: true);

        $this->assertCount(4, $seen);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('paid: 401 as expected', $output);
        $this->assertStringContainsString('refund: https://shop.test/fawaterk/webhooks/refund_json answered 200, expected 401', $output);
    }

    public function test_missing_routes_and_recipients_only_warn(): void
    {
        $this->app['router']->setRoutes(new RouteCollection);
        config()->set('fawaterk.notifications.mail', null);

        [$code, $output] = $this->doctor();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('WARN  Route::fawaterkWebhooks() is not registered', $output);
        $this->assertStringContainsString('WARN  Route::fawaterk() is not registered', $output);
        $this->assertStringContainsString('WARN  No anomaly mail recipient', $output);
    }

    public function test_a_short_result_key_and_a_bad_profile_fail(): void
    {
        config()->set('fawaterk.results.key', 'short');
        config()->set('fawaterk.profiles.broken', ['kind' => 'method']);

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAWATERK_RESULT_KEY must be at least 32 characters long', $output);
        $this->assertStringContainsString('Profile [broken] needs a method', $output);
    }

    public function test_blocking_flags_are_listed_as_warnings(): void
    {
        $flagged = $this->paidRow(['flags' => [Flag::PaidTwice->value => 'now']]);

        [$code, $output] = $this->doctor();

        $this->assertSame(0, $code, 'a settled anomaly someone may already have handled does not fail the install check');
        $this->assertStringContainsString('WARN  1 payment(s) with a blocking flag in the last 30 days', $output);
        $this->assertStringContainsString($flagged->uuid, $output);
    }

    public function test_unconfirmed_refunds_and_unknown_paid_checkouts_are_listed(): void
    {
        $refund = $this->paidRow(['flags' => [Flag::RefundUnverified->value => 'now'], 'fulfilled_at' => Carbon::now()]);
        $intent = (string) Str::uuid();
        WebhookEvent::query()->create([
            'account' => 'default', 'environment' => 'staging', 'type' => 'paid', 'outcome' => 'unknown_payment',
            'intent_key' => $intent, 'dedupe_key' => 'unknown_paid:'.$intent, 'created_at' => Carbon::now(),
        ]);

        [, $output] = $this->doctor();

        $this->assertStringContainsString('1 payment(s) with a refund the refund list did not confirm', $output);
        $this->assertStringContainsString($refund->uuid, $output);
        $this->assertStringContainsString('1 paid checkout(s) this app did not create', $output);
        $this->assertStringContainsString($intent, $output);
    }

    public function test_an_unknown_intent_fawaterk_did_not_report_paid_is_not_listed(): void
    {
        // A signed webhook for an intent nobody knows, which the re-read found unpaid or missing: the same outcome
        // name, but no "unknown_paid:" dedupe key.
        WebhookEvent::query()->create([
            'account' => 'default', 'environment' => 'staging', 'type' => 'paid', 'outcome' => 'unknown_payment',
            'intent_key' => 'qqqqqqqqqqqqqqqqqq', 'dedupe_key' => null, 'created_at' => Carbon::now(),
        ]);

        [, $output] = $this->doctor();

        $this->assertStringNotContainsString('this app did not create', $output);
        $this->assertStringNotContainsString('qqqqqqqqqqqqqqqqqq', $output);
    }

    public function test_refund_webhooks_at_another_url_are_a_warning(): void
    {
        foreach (['failed', 'failed', 'paid'] as $type) {
            WebhookEvent::query()->create([
                'account' => 'default', 'environment' => 'staging', 'type' => $type, 'outcome' => 'misrouted',
                'intent_key' => null, 'dedupe_key' => 'refund:555|4000|EGP', 'created_at' => Carbon::now(),
            ]);
        }

        [, $output] = $this->doctor();

        $this->assertStringContainsString('WARN  3 refund webhook(s) reached another webhook URL in the last 30 days (failed, paid)', $output);
        $this->assertStringContainsString("dashboard's Refund field", $output);
    }

    public function test_a_payment_reconcile_just_found_is_not_undelivered_yet(): void
    {
        $this->paidRow(['paid_at' => Carbon::now()->subHours(3), 'last_checked_at' => Carbon::now()->subMinutes(2)]);

        [$code, $output] = $this->doctor();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('No paid payment waiting for delivery', $output);
    }

    public function test_it_checks_for_a_payment_paid_listener(): void
    {
        [$code, $output] = $this->doctor();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('WARN  Nothing listens to PaymentPaid', $output);

        config()->set('fawaterk.fulfilment', 'after_listeners');
        [$code, $output] = $this->doctor();
        $this->assertSame(1, $code, 'every payment would be marked delivered with nothing delivered');
        $this->assertStringContainsString('FAIL  Nothing listens to PaymentPaid', $output);

        Event::listen(PaymentPaid::class, fn () => null);
        [$code, $output] = $this->doctor();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('OK    PaymentPaid has a listener', $output);
    }

    public function test_routes_the_package_cannot_build_urls_for_fail_without_crashing(): void
    {
        $router = $this->app['router'];
        $router->setRoutes(new RouteCollection);
        $router->prefix('{locale}')->group(function () use ($router) {
            $router->fawaterkWebhooks();
            $router->fawaterk();
        });

        [$code, $output] = $this->doctor();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('{locale}', $output);
    }

    public function test_install_publishes_once_and_prints_what_to_add(): void
    {
        $code = Artisan::call('fawaterk:install', ['--no-doctor' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertFileExists(config_path('fawaterk.php'));
        $this->assertCount(1, glob(database_path('migrations/*_create_fawaterk_tables.php')) ?: []);
        $this->assertStringContainsString('FAWATERK_CLIENT_SECRET=', $output);
        $this->assertStringContainsString('[set]', $output);
        $this->assertStringContainsString('[not set]', $output);
        $this->assertStringContainsString("Route::fawaterkWebhooks('fawaterk/webhooks');", $output);
        $this->assertStringContainsString("Route::fawaterk('fawaterk');", $output);
        $this->assertStringContainsString("command('fawaterk:reconcile')->everyFiveMinutes()->withoutOverlapping(15);", $output);
        $this->assertStringContainsString("command('model:prune', ['--model' => [\\BiztechEG\\Fawaterk\\Webhooks\\WebhookEvent::class]])->daily();", $output);
        $this->assertStringNotContainsString('doctor-client-secret-value', $output);

        file_put_contents(config_path('fawaterk.php'), '<?php return ["edited" => true];');
        // As if it had been published on an earlier day (a new run would use a new timestamp).
        $published = (glob(database_path('migrations/*_create_fawaterk_tables.php')) ?: [])[0];
        rename($published, database_path('migrations/2020_01_01_000000_create_fawaterk_tables.php'));
        Artisan::call('fawaterk:install', ['--no-doctor' => true]);

        $this->assertStringContainsString('already exists', Artisan::output());
        $this->assertStringContainsString('edited', (string) file_get_contents(config_path('fawaterk.php')), 'the app config is kept');
        $this->assertCount(1, glob(database_path('migrations/*_create_fawaterk_tables.php')) ?: [], 'never a second migration');
    }

    public function test_install_runs_the_offline_doctor_and_still_succeeds(): void
    {
        config()->set('fawaterk.client_secret', null);

        $code = Artisan::call('fawaterk:install');
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('FAIL  FAWATERK_CLIENT_SECRET is not set', $output);
        $this->assertStringContainsString('skipped (--offline)', $output);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function doctor(bool $online = false, bool $probe = false): array
    {
        $code = Artisan::call('fawaterk:doctor', array_filter(['--offline' => ! $online, '--probe' => $probe]));

        return [$code, Artisan::output()];
    }

    private function heartbeat(Carbon $at): void
    {
        Cache::store(config('fawaterk.cache_store'))->forever($this->app->make(Reconciler::class)->heartbeatKey(), $at->getTimestamp());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function paidRow(array $attributes): FawaterkPayment
    {
        $payment = $this->payment(Fawaterk::checkout($this->order())->paymentUuid);
        $payment->forceFill(array_merge([
            'status' => PaymentStatus::Paid,
            'paid_amount_minor' => 15000,
            'paid_at' => Carbon::now()->subMinutes(5),
            'fulfilled_at' => null,
        ], $attributes))->save();

        return $payment;
    }
}

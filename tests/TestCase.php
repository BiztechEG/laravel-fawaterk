<?php

namespace BiztechEG\Fawaterk\Tests;

use BiztechEG\Fawaterk\FawaterkServiceProvider;
use Closure;
use Illuminate\Http\Client\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [FawaterkServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Test-only credentials. Call it before anything resolves the package.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function useCredentials(array $overrides = []): void
    {
        config()->set('fawaterk', array_merge((array) config('fawaterk'), [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'vendor_api_key' => 'test-vendor-key',
        ], $overrides));
    }

    /**
     * Laravel's assertThrows() only exists from Laravel 10.
     *
     * @param  class-string<\Throwable>  $class
     */
    protected function assertRaises(Closure $callback, string $class, ?string $messageContains = null): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, 'Got '.get_class($e).': '.$e->getMessage());

            if ($messageContains !== null) {
                $this->assertStringContainsString($messageContains, $e->getMessage());
            }

            return;
        }

        $this->fail("{$class} was not thrown.");
    }

    /**
     * Fake the package's own HTTP factory (the one the provider binds), so a
     * test also proves which factory the package really uses. Any request the
     * handler does not answer fails the test.
     */
    protected function fakeFawaterkHttp(Closure $handler): Factory
    {
        /** @var Factory $factory */
        $factory = $this->app->make('fawaterk.http');
        $factory->fake($handler);
        $factory->preventStrayRequests();

        return $factory;
    }
}

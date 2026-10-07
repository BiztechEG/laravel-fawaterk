<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Auth\AccessTokenProvider;
use BiztechEG\Fawaterk\Exceptions\AuthenticationException;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use BiztechEG\Fawaterk\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class AccessTokenProviderTest extends TestCase
{
    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCredentials();
    }

    public function test_the_token_is_cached_encrypted(): void
    {
        $this->tokenEndpoint();

        $this->assertSame('tok-1', $this->provider()->token($this->credentials()));
        $this->assertSame('tok-1', $this->provider()->token($this->credentials()));
        $this->assertSame(1, $this->requests);

        $raw = Cache::get($this->key());
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('tok-1', $raw);
        $this->assertSame('tok-1', Crypt::decryptString($raw));
    }

    public function test_a_year_long_token_is_kept_for_at_most_24_hours(): void
    {
        $this->tokenEndpoint(31536000);

        $this->provider()->token($this->credentials());

        $this->travel(86399)->seconds();
        $this->assertSame('tok-1', $this->provider()->token($this->credentials()));

        $this->travel(2)->seconds();
        $this->assertSame('tok-2', $this->provider()->token($this->credentials()));
    }

    public function test_a_short_token_expires_five_minutes_early(): void
    {
        $this->tokenEndpoint(400);

        $this->provider()->token($this->credentials());

        $this->travel(99)->seconds();
        $this->assertSame('tok-1', $this->provider()->token($this->credentials()));

        $this->travel(2)->seconds();
        $this->assertSame('tok-2', $this->provider()->token($this->credentials()));
    }

    public function test_an_unreadable_cached_token_is_a_cache_miss(): void
    {
        $this->tokenEndpoint();
        Cache::put($this->key(), 'encrypted-with-an-old-app-key', 3600);

        $this->assertSame('tok-1', $this->provider()->token($this->credentials()));
        $this->assertSame('tok-1', Crypt::decryptString(Cache::get($this->key())));
    }

    public function test_forget_drops_the_token(): void
    {
        $this->tokenEndpoint();

        $this->provider()->token($this->credentials());
        $this->provider()->forget($this->credentials());

        $this->assertNull(Cache::get($this->key()));
        $this->assertSame('tok-2', $this->provider()->token($this->credentials()));
    }

    public function test_a_missing_secret_fails_before_any_request(): void
    {
        config()->set('fawaterk.client_secret', '');
        $this->fakeFawaterkHttp(fn () => $this->fail('No request should be sent.'));

        $this->assertRaises(fn () => $this->provider()->token($this->credentials()), ConfigurationException::class, 'FAWATERK_CLIENT_SECRET');
    }

    public function test_token_endpoint_failures_are_typed_and_nothing_is_cached(): void
    {
        $answers = [
            [Factory::response(['error' => 'invalid_client'], 400), AuthenticationException::class],
            [Factory::response(['error' => 'invalid_client'], 401), AuthenticationException::class],
            [Factory::response('down', 503), ServiceUnavailableException::class],
            [Factory::response('slow down', 429), ServiceUnavailableException::class],
            [Factory::response(['token_type' => 'Bearer'], 200), UnexpectedResponseException::class],
            [Factory::response(['access_token' => ''], 200), UnexpectedResponseException::class],
            [Factory::response('<html>login</html>', 200), UnexpectedResponseException::class],
            [Factory::response(['access_token' => 'x'], 201), UnexpectedResponseException::class],
        ];

        $current = null;
        $this->fakeFawaterkHttp(function () use (&$current) {
            return $current;
        });

        foreach ($answers as [$response, $exception]) {
            $current = $response;
            $this->assertRaises(fn () => $this->provider()->token($this->credentials()), $exception);
            $this->assertNull(Cache::get($this->key()));
        }
    }

    private function tokenEndpoint(int $expiresIn = 31536000): void
    {
        $this->fakeFawaterkHttp(function () use ($expiresIn) {
            $this->requests++;

            return Factory::response(['token_type' => 'Bearer', 'expires_in' => $expiresIn, 'access_token' => "tok-{$this->requests}"]);
        });
    }

    private function provider(): AccessTokenProvider
    {
        return $this->app->make(AccessTokenProvider::class);
    }

    private function credentials(): Credentials
    {
        return $this->app->make(AccountRepository::class)->get();
    }

    private function key(): string
    {
        return $this->credentials()->cachePrefix().':token';
    }
}

<?php

namespace BiztechEG\Fawaterk\Tests\Unit;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Environment;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use PHPUnit\Framework\TestCase;

class AccountRepositoryTest extends TestCase
{
    public function test_the_base_url_is_fixed_by_the_environment(): void
    {
        $this->assertSame('https://staging.fawaterk.com', (new AccountRepository([]))->get()->baseUrl);
        $this->assertSame('https://app.fawaterk.com', (new AccountRepository(['environment' => 'live']))->get()->baseUrl);
        $this->assertSame(Environment::Live, (new AccountRepository(['environment' => ' LIVE ']))->get()->environment);
    }

    public function test_no_setting_can_change_the_host(): void
    {
        foreach (['https://evil.test', 'https://app.fawaterak.com', 'https://app.fawaterk.com'] as $url) {
            $config = ['base_url' => $url, 'url' => $url, 'host' => $url];

            $this->assertSame('https://staging.fawaterk.com', (new AccountRepository($config))->get()->baseUrl);
        }
    }

    public function test_an_unknown_environment_or_account_is_refused(): void
    {
        foreach ([['environment' => 'production'], ['environment' => '']] as $config) {
            try {
                (new AccountRepository($config))->get();
                $this->fail('Accepted: '.json_encode($config));
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ConfigurationException::class);
        (new AccountRepository([]))->get('second');
    }

    public function test_secrets_are_checked_only_when_used(): void
    {
        $credentials = (new AccountRepository(['client_id' => '  ']))->get();

        $this->assertSame('https://staging.fawaterk.com', $credentials->baseUrl, 'building credentials never fails on secrets');

        foreach (['clientId', 'clientSecret', 'vendorApiKey'] as $method) {
            try {
                $credentials->{$method}();
                $this->fail("{$method}() did not fail without a value.");
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('FAWATERK_', $e->getMessage());
            }
        }
    }

    public function test_secrets_are_hidden_from_dumps(): void
    {
        $credentials = (new AccountRepository(['client_id' => 'id-123', 'client_secret' => 'secret-456', 'vendor_api_key' => 'key-789']))->get();

        $dump = print_r($credentials, true);

        foreach (['id-123', 'secret-456', 'key-789'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_cache_keys_are_scoped_and_never_contain_the_client_id(): void
    {
        $staging = (new AccountRepository(['client_id' => 'my-client-id']))->get();
        $live = (new AccountRepository(['client_id' => 'my-client-id', 'environment' => 'live']))->get();

        $this->assertStringStartsWith('fawaterk:default:staging:', $staging->cachePrefix());
        $this->assertNotSame($staging->cachePrefix(), $live->cachePrefix());
        $this->assertStringNotContainsString('my-client-id', $staging->cachePrefix());
    }
}

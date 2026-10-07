<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentDataParser;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Http\Client;
use BiztechEG\Fawaterk\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Route;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_with_safe_defaults(): void
    {
        $this->assertSame('staging', config('fawaterk.environment'));
        $this->assertSame(20, config('fawaterk.http.timeout'));
        $this->assertSame(5, config('fawaterk.http.connect_timeout'));
        $this->assertSame([], config('fawaterk.methods'));
        $this->assertSame(600, config('fawaterk.methods_cache_ttl'));
        $this->assertSame('Africa/Cairo', config('fawaterk.provider_timezone'));
    }

    public function test_secrets_have_no_default_values(): void
    {
        $this->assertNull(config('fawaterk.client_id'));
        $this->assertNull(config('fawaterk.client_secret'));
        $this->assertNull(config('fawaterk.vendor_api_key'));
    }

    public function test_installing_the_package_registers_no_routes(): void
    {
        $packageRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => stripos($route->uri(), 'fawaterk') !== false
                || stripos((string) $route->getName(), 'fawaterk') !== false
                || str_contains($route->getActionName(), 'BiztechEG\\Fawaterk'))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $packageRoutes);
    }

    public function test_the_client_resolves_without_secrets_and_through_the_facade(): void
    {
        $this->assertInstanceOf(Client::class, $this->app->make(FawaterkClient::class));
        $this->assertInstanceOf(Client::class, Fawaterk::client());
    }

    public function test_the_package_uses_its_own_http_factory_without_events(): void
    {
        $factory = $this->app->make('fawaterk.http');

        $this->assertInstanceOf(Factory::class, $factory);
        $this->assertNotSame($this->app->make(Factory::class), $factory);
        $this->assertSame($factory, $this->app->make('fawaterk.http'));
        $this->assertNull($factory->getDispatcher());
    }

    public function test_bad_config_fails_when_the_package_is_used_not_at_boot(): void
    {
        config()->set('fawaterk.environment', 'production');
        config()->set('fawaterk.provider_timezone', 'Mars/Base');

        $this->assertRaises(fn () => $this->app->make(FawaterkClient::class), ConfigurationException::class);
        $this->assertRaises(fn () => $this->app->make(PaymentDataParser::class), ConfigurationException::class, 'Mars/Base');
    }
}

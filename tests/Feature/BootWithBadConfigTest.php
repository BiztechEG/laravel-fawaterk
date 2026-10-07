<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\PaymentData\PaymentDataParser;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Tests\TestCase;

/**
 * The config is broken before the app boots, so a regression that validates
 * config (or reads secrets) at boot fails this test.
 */
class BootWithBadConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('fawaterk.environment', 'production');
        $app['config']->set('fawaterk.provider_timezone', 'Mars/Base');
        $app['config']->set('fawaterk.client_id', null);
        $app['config']->set('fawaterk.client_secret', null);
        $app['config']->set('fawaterk.vendor_api_key', null);
    }

    public function test_the_app_boots_and_the_package_fails_only_when_used(): void
    {
        $this->assertTrue($this->app->isBooted());

        $this->assertRaises(fn () => $this->app->make(FawaterkClient::class), ConfigurationException::class);
        $this->assertRaises(fn () => $this->app->make(PaymentDataParser::class), ConfigurationException::class, 'Mars/Base');
    }

    public function test_the_fake_still_works(): void
    {
        $this->assertSame(Fawaterk::fake(), Fawaterk::client());
    }
}

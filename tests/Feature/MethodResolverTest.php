<?php

namespace BiztechEG\Fawaterk\Tests\Feature;

use BiztechEG\Fawaterk\Accounts\AccountRepository;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Facades\Fawaterk;
use BiztechEG\Fawaterk\Testing\FawaterkFake;
use BiztechEG\Fawaterk\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class MethodResolverTest extends TestCase
{
    private FawaterkFake $fake;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('fawaterk.methods', [
            'fawry' => ['id' => ['staging' => 3, 'live' => 12]],
            'aman' => ['id' => ['staging' => 4]],
            'card' => ['name_en' => 'visa mastercard'],
            'visa' => ['name_en' => 'Visa'],
            'wallet' => ['name_en' => 'Mobile Wallet'],
            'meeza' => ['name_en' => 'Meeza'],
            'broken' => ['id' => ['staging' => 0]],
            'scalar' => ['id' => 3],
            'empty' => [],
        ]);

        $this->fake = Fawaterk::fake()->setPaymentMethods(
            new PaymentMethod(2, 'Visa-Mastercard', null, true, false),
            new PaymentMethod(3, 'Fawry', null, false, true),
            new PaymentMethod(4, 'Aman', null, false, true),
            new PaymentMethod(12, 'Fawry', null, false, true),
            new PaymentMethod(20, 'Mobile Wallet', null, false, null),
            new PaymentMethod(21, 'Mobile-Wallet', null, false, null),
        );
    }

    public function test_the_id_for_the_current_environment_is_used(): void
    {
        $this->assertSame(3, Fawaterk::methods()->resolve('fawry')->id);
        $this->assertSame(4, Fawaterk::methods()->resolve('aman')->id);
    }

    public function test_the_live_id_is_used_in_live(): void
    {
        config()->set('fawaterk.environment', 'live');

        $this->assertSame(12, Fawaterk::methods()->resolve('fawry')->id);
        $this->assertRaises(fn () => Fawaterk::methods()->resolve('aman'), ConfigurationException::class, 'no id for the live environment');
    }

    public function test_an_english_name_must_match_exactly_after_normalising(): void
    {
        $this->assertSame(2, Fawaterk::methods()->resolve('card')->id);
        $this->assertRaises(fn () => Fawaterk::methods()->resolve('visa'), ConfigurationException::class, 'not enabled');
    }

    public function test_zero_or_several_matches_and_bad_config_are_refused(): void
    {
        $cases = [
            'meeza' => 'not enabled',
            'wallet' => 'matches several',
            'broken' => 'has no id',
            'scalar' => 'per environment',
            'empty' => 'needs an id or a name_en',
            'unknown' => 'is not configured',
        ];

        foreach ($cases as $name => $message) {
            $this->assertRaises(fn () => Fawaterk::methods()->resolve($name), ConfigurationException::class, $message);
        }
    }

    public function test_the_list_is_cached_briefly_and_can_be_forgotten(): void
    {
        Fawaterk::methods()->resolve('fawry');
        Fawaterk::methods()->resolve('card');
        $this->assertSame(1, $this->fake->paymentMethodCalls());

        Fawaterk::methods()->forget();
        Fawaterk::methods()->resolve('fawry');
        $this->assertSame(2, $this->fake->paymentMethodCalls());

        $this->travel(601)->seconds();
        Fawaterk::methods()->resolve('fawry');
        $this->assertSame(3, $this->fake->paymentMethodCalls());
    }

    public function test_a_corrupted_cache_entry_is_fetched_again(): void
    {
        $key = $this->app->make(AccountRepository::class)->get()->cachePrefix().':methods';
        Cache::put($key, [['name_en' => 'no id']], 600);

        $this->assertSame(3, Fawaterk::methods()->resolve('fawry')->id);
        $this->assertSame(1, $this->fake->paymentMethodCalls());
    }
}

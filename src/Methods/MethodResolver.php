<?php

namespace BiztechEG\Fawaterk\Methods;

use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Contracts\FawaterkClient;
use BiztechEG\Fawaterk\Data\PaymentMethod;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Maps the method names used in your config ("fawry", "card") to Fawaterk
 * payment methods. Ids can differ per account and environment, so an explicit
 * id per environment is preferred; an exact English name also works. Anything
 * that matches zero or several methods is refused.
 */
final class MethodResolver
{
    /**
     * @param  array<string, mixed>  $methods  the "fawaterk.methods" config
     */
    public function __construct(
        private readonly FawaterkClient $client,
        private readonly CacheRepository $cache,
        private readonly Credentials $credentials,
        private readonly array $methods,
        private readonly int $ttl = 600,
    ) {}

    public function resolve(string $name): PaymentMethod
    {
        $config = $this->methods[$name] ?? null;

        if (! is_array($config)) {
            throw new ConfigurationException("Payment method [{$name}] is not configured in fawaterk.methods.");
        }

        $all = $this->all();

        if (array_key_exists('id', $config)) {
            $id = $this->configuredId($name, $config['id']);
            $matches = array_filter($all, fn (PaymentMethod $method) => $method->id === $id);
        } elseif (is_string($config['name_en'] ?? null) && trim($config['name_en']) !== '') {
            $wanted = PaymentMethod::normaliseName($config['name_en']);
            $matches = array_filter($all, fn (PaymentMethod $method) => PaymentMethod::normaliseName($method->nameEn) === $wanted);
        } else {
            throw new ConfigurationException("Payment method [{$name}] needs an id or a name_en.");
        }

        if (count($matches) === 0) {
            throw new ConfigurationException(
                "Payment method [{$name}] is not enabled on the Fawaterk account (check its Integration status)."
            );
        }

        if (count($matches) > 1) {
            throw new ConfigurationException("Payment method [{$name}] matches several methods; configure an explicit id.");
        }

        return array_values($matches)[0];
    }

    /**
     * The account's integration-enabled methods, cached briefly.
     *
     * @return list<PaymentMethod>
     */
    public function all(): array
    {
        $cached = $this->cache->get($this->key());

        if (is_array($cached)) {
            try {
                return array_values(array_map(fn ($item) => PaymentMethod::fromArray($item), $cached));
            } catch (UnexpectedResponseException) {
                $this->forget();
            }
        }

        $methods = $this->client->getPaymentMethods();
        $this->cache->put($this->key(), array_map(fn (PaymentMethod $method) => $method->toArray(), $methods), $this->ttl);

        return $methods;
    }

    public function findById(int $id): ?PaymentMethod
    {
        foreach ($this->all() as $method) {
            if ($method->id === $id) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The method a re-read names. Fawaterk localises that name, so it is matched
     * against both name_en and name_ar; anything but exactly one match is null.
     */
    public function findByName(string $name): ?PaymentMethod
    {
        $wanted = PaymentMethod::normaliseName($name);

        if ($wanted === '') {
            return null;
        }

        $matches = array_values(array_filter($this->all(), fn (PaymentMethod $method) => PaymentMethod::normaliseName($method->nameEn) === $wanted
            || ($method->nameAr !== null && PaymentMethod::normaliseName($method->nameAr) === $wanted)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Drop the cached list, for example after Fawaterk rejects a method id.
     */
    public function forget(): void
    {
        $this->cache->forget($this->key());
    }

    private function configuredId(string $name, mixed $id): int
    {
        // Ids differ between staging and live: one id for both would silently
        // pick another method on live.
        if (! is_array($id)) {
            throw new ConfigurationException(
                "Payment method [{$name}] needs its id per environment, for example ['staging' => 3, 'live' => 12]."
            );
        }

        $id = $id[$this->credentials->environment->value] ?? null;

        if (is_int($id) && $id > 0) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }

        throw new ConfigurationException(
            "Payment method [{$name}] has no id for the {$this->credentials->environment->value} environment."
        );
    }

    private function key(): string
    {
        return $this->credentials->cachePrefix().':methods';
    }
}

<?php

namespace BiztechEG\Fawaterk\Accounts;

use BiztechEG\Fawaterk\Environment;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;

/**
 * Builds the credentials of a Fawaterk account from config. Version 1.0 has a
 * single account, "default"; the lookup by name keeps room for more later.
 */
final class AccountRepository
{
    /**
     * @param  array<string, mixed>  $config  the "fawaterk" config array
     */
    public function __construct(#[\SensitiveParameter] private readonly array $config) {}

    public function get(string $account = 'default'): Credentials
    {
        if ($account !== 'default') {
            throw new ConfigurationException("Fawaterk account [{$account}] is not configured.");
        }

        $environment = Environment::fromConfig($this->config['environment'] ?? 'staging');

        // The base URL comes only from the environment and is fixed in code, so
        // no setting can send the credentials to another host.
        return new Credentials(
            account: $account,
            environment: $environment,
            baseUrl: $environment->baseUrl(),
            clientId: $this->string('client_id'),
            clientSecret: $this->string('client_secret'),
            vendorApiKey: $this->string('vendor_api_key'),
            timeout: max(1, (int) ($this->config['http']['timeout'] ?? 20)),
            connectTimeout: max(1, (int) ($this->config['http']['connect_timeout'] ?? 5)),
        );
    }

    private function string(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}

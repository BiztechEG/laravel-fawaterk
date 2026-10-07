<?php

namespace BiztechEG\Fawaterk\Accounts;

use BiztechEG\Fawaterk\Environment;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;

/**
 * One Fawaterk account's settings. Secrets are checked when they are first
 * used, never when the object is built.
 */
final class Credentials
{
    public function __construct(
        public readonly string $account,
        public readonly Environment $environment,
        public readonly string $baseUrl,
        #[\SensitiveParameter] private readonly ?string $clientId,
        #[\SensitiveParameter] private readonly ?string $clientSecret,
        #[\SensitiveParameter] private readonly ?string $vendorApiKey,
        public readonly int $timeout = 20,
        public readonly int $connectTimeout = 5,
    ) {}

    /**
     * Keeps secrets out of dump(), dd(), var_dump() and print_r().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'account' => $this->account,
            'environment' => $this->environment->value,
            'baseUrl' => $this->baseUrl,
            'clientId' => $this->clientId === null ? null : '[redacted]',
            'clientSecret' => $this->clientSecret === null ? null : '[redacted]',
            'vendorApiKey' => $this->vendorApiKey === null ? null : '[redacted]',
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
        ];
    }

    public function clientId(): string
    {
        return $this->require($this->clientId, 'FAWATERK_CLIENT_ID');
    }

    public function clientSecret(): string
    {
        return $this->require($this->clientSecret, 'FAWATERK_CLIENT_SECRET');
    }

    public function vendorApiKey(): string
    {
        return $this->require($this->vendorApiKey, 'FAWATERK_VENDOR_API_KEY');
    }

    /**
     * Cache keys are scoped to the account, the environment and the OAuth
     * client, so a changed client or environment never reuses old entries.
     * It does not require a client id: Fawaterk::fake() needs no credentials,
     * and without one the real client fails before it caches anything.
     */
    public function cachePrefix(): string
    {
        $client = $this->clientId === null || trim($this->clientId) === ''
            ? 'no-client'
            : substr(hash('sha256', $this->clientId), 0, 16);

        return sprintf('fawaterk:%s:%s:%s', $this->account, $this->environment->value, $client);
    }

    public function url(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    private function require(?string $value, string $name): string
    {
        if ($value === null || trim($value) === '') {
            throw new ConfigurationException("{$name} is not set.");
        }

        return $value;
    }
}

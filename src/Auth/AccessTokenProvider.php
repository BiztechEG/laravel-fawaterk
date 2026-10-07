<?php

namespace BiztechEG\Fawaterk\Auth;

use BiztechEG\Fawaterk\Accounts\Credentials;
use BiztechEG\Fawaterk\Exceptions\AuthenticationException;
use BiztechEG\Fawaterk\Exceptions\ServiceUnavailableException;
use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;
use BiztechEG\Fawaterk\Http\TransportFailure;
use BiztechEG\Fawaterk\Support\Json;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;

/**
 * OAuth 2.0 client-credentials tokens for /api/v3.
 *
 * Tokens are cached encrypted and for at most 24 hours, even though Fawaterk
 * issues them for a year. No refresh token is stored: a new token is simply
 * requested with the client credentials.
 */
final class AccessTokenProvider
{
    private const MAX_TTL = 86400;

    private const EARLY_EXPIRY = 300;

    public function __construct(
        private readonly Factory $http,
        private readonly CacheRepository $cache,
        private readonly StringEncrypter $encrypter,
    ) {}

    /**
     * @param  int|null  $timeout  the caller's time budget in seconds; the lock
     *                             wait and the token request stay within it
     */
    public function token(Credentials $credentials, ?int $timeout = null): string
    {
        $cached = $this->cached($credentials);

        if ($cached !== null) {
            return $cached;
        }

        $timeout = max(1, min($timeout ?? $credentials->timeout, $credentials->timeout));
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->fetch($credentials, $timeout);
        }

        try {
            return $store->lock($this->key($credentials).':lock', 15)->block(min(10, $timeout), function () use ($credentials, $timeout) {
                return $this->cached($credentials) ?? $this->fetch($credentials, $timeout);
            });
        } catch (LockTimeoutException) {
            // A duplicate token request is harmless; waiting forever is not.
            return $this->fetch($credentials, $timeout);
        }
    }

    public function forget(Credentials $credentials): void
    {
        $this->cache->forget($this->key($credentials));
    }

    private function cached(Credentials $credentials): ?string
    {
        $value = $this->cache->get($this->key($credentials));

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return $this->encrypter->decryptString($value);
        } catch (DecryptException) {
            // For example after an APP_KEY rotation: treat it as a cache miss.
            $this->forget($credentials);

            return null;
        }
    }

    private function fetch(Credentials $credentials, int $timeout): string
    {
        $body = [
            'grant_type' => 'client_credentials',
            'client_id' => $credentials->clientId(),
            'client_secret' => $credentials->clientSecret(),
        ];

        try {
            $response = $this->http
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->connectTimeout(min($credentials->connectTimeout, $timeout))
                ->post($credentials->url('/oauth/token'), $body);
        } catch (HttpClientException|TransferException $e) {
            throw TransportFailure::from($e, 'Fawaterk could not be reached to get an access token.');
        }

        $status = $response->status();

        if (in_array($status, [400, 401], true)) {
            throw new AuthenticationException('Fawaterk rejected the OAuth client credentials.', $status);
        }

        if ($status >= 500 || $status === 429) {
            throw new ServiceUnavailableException('Fawaterk could not issue an access token right now.', $status);
        }

        $json = Json::decodeObject($response->body());
        $token = $json['access_token'] ?? null;

        if ($status !== 200 || ! is_string($token) || $token === '') {
            throw new UnexpectedResponseException("Fawaterk returned no access token (HTTP {$status}).");
        }

        $expiresIn = is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : 3600;
        $ttl = max(60, min($expiresIn - self::EARLY_EXPIRY, self::MAX_TTL));

        $this->cache->put($this->key($credentials), $this->encrypter->encryptString($token), $ttl);

        return $token;
    }

    private function key(Credentials $credentials): string
    {
        return $credentials->cachePrefix().':token';
    }
}

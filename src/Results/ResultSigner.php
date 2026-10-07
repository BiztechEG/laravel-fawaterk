<?php

namespace BiztechEG\Fawaterk\Results;

use BiztechEG\Fawaterk\Exceptions\ConfigurationException;

/**
 * Signs result URLs in the path: /{prefix}/result/{uuid}/{expires}/{sig}.
 *
 * The signature covers the payment uuid and the expiry only, so the query
 * string Fawaterk appends to redirects never matters. The key is
 * FAWATERK_RESULT_KEY, or one derived from APP_KEY when it is not set.
 *
 * @internal
 */
final class ResultSigner
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(
        #[\SensitiveParameter] private readonly ?string $resultKey,
        #[\SensitiveParameter] private readonly ?string $appKey,
    ) {}

    public function sign(string $uuid, int $expires): string
    {
        return hash_hmac('sha256', self::message($uuid, $expires), $this->key());
    }

    /**
     * True only for a well-formed, unexpired, correctly signed triple.
     */
    public function verify(string $uuid, string $expires, string $signature, int $now): bool
    {
        if (! preg_match(self::UUID, $uuid)
            || ! preg_match('/^[1-9][0-9]{0,11}$/D', $expires)
            || ! preg_match('/^[0-9a-f]{64}$/D', $signature)) {
            return false;
        }

        // The signature is checked before the expiry, so an expired link and
        // a forged one take the same path through the code.
        $valid = hash_equals($this->sign($uuid, (int) $expires), $signature);

        return $valid && (int) $expires >= $now;
    }

    /**
     * Whether a usable key is configured (for fawaterk:doctor).
     */
    public function describeKey(): string
    {
        return $this->resultKey !== null && $this->resultKey !== '' ? 'FAWATERK_RESULT_KEY' : 'derived from APP_KEY';
    }

    private function key(): string
    {
        if ($this->resultKey !== null && $this->resultKey !== '') {
            if (strlen($this->resultKey) < 32) {
                throw new ConfigurationException('FAWATERK_RESULT_KEY must be at least 32 characters long.');
            }

            return $this->resultKey;
        }

        if ($this->appKey === null || $this->appKey === '') {
            throw new ConfigurationException('Set FAWATERK_RESULT_KEY (or APP_KEY) to sign result URLs.');
        }

        // A key of its own, so a result signature is never valid anywhere else
        // APP_KEY is used.
        return hash_hmac('sha256', 'fawaterk:result-urls', $this->appKey);
    }

    private static function message(string $uuid, int $expires): string
    {
        return 'fawaterk-result|v1|'.$uuid.'|'.$expires;
    }
}

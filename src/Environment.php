<?php

namespace BiztechEG\Fawaterk;

use BiztechEG\Fawaterk\Exceptions\ConfigurationException;

enum Environment: string
{
    case Staging = 'staging';
    case Live = 'live';

    /**
     * The only hosts the package ever sends credentials to. There is no
     * setting to change them.
     */
    public function baseUrl(): string
    {
        return match ($this) {
            self::Staging => 'https://staging.fawaterk.com',
            self::Live => 'https://app.fawaterk.com',
        };
    }

    public static function fromConfig(mixed $value): self
    {
        $environment = is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;

        if ($environment === null) {
            throw new ConfigurationException('FAWATERK_ENV must be "staging" or "live".');
        }

        return $environment;
    }
}

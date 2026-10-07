<?php

namespace BiztechEG\Fawaterk\Checkout;

use BiztechEG\Fawaterk\Data\RedirectionUrls;
use BiztechEG\Fawaterk\Exceptions\ConfigurationException;
use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;

/**
 * A payment profile from fawaterk.profiles:
 *
 * - kind "hosted": Fawaterk's page with every method enabled on the account
 * - kind "method" + method: one method from fawaterk.methods. A code method
 *   (Fawry, Aman, Masary) returns a reference code; a card method returns
 *   Fawaterk's page with that method preselected
 *
 * Options: lang (ar|en), list_style (h|v), due_after (minutes), send_email,
 * send_sms, reuse (default true), return_urls (success, fail, pending, back),
 * code_validity (due_date|code_expiry; default fawaterk.code_validity).
 */
final class Profile
{
    public const RETURN_URLS = ['success', 'fail', 'pending', 'back'];

    /**
     * @param  array<string, string>  $returnUrls
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $method = null,
        public readonly ?string $lang = null,
        public readonly ?string $listStyle = null,
        public readonly ?int $dueAfter = null,
        public readonly bool $sendEmail = false,
        public readonly bool $sendSms = false,
        public readonly bool $reuse = true,
        public readonly array $returnUrls = [],
        public readonly string $codeValidity = self::DUE_DATE,
    ) {}

    /** A reference code is valid until the due date asked for, and never past its own expiry. */
    public const DUE_DATE = 'due_date';

    /** A reference code is valid until its own expiry at the outlet (Fawry gives four days), whatever the due date. */
    public const CODE_EXPIRY = 'code_expiry';

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $overrides  per-call options from CheckoutContext
     * @param  list<string>  $allowedHosts  hosts return URLs may point at
     */
    public static function fromConfig(string $name, array $config, array $overrides, array $allowedHosts): self
    {
        $options = array_merge($config, $overrides);
        $kind = $options['kind'] ?? null;

        if ($kind === 'group') {
            throw new ConfigurationException("Profile [{$name}]: the group kind arrives in v1.1.");
        }

        if (! in_array($kind, ['hosted', 'method'], true)) {
            throw new ConfigurationException("Profile [{$name}] needs a kind: hosted or method.");
        }

        $method = $options['method'] ?? null;

        if ($kind === 'method' && (! is_string($method) || $method === '')) {
            throw new ConfigurationException("Profile [{$name}] needs a method (a name from fawaterk.methods).");
        }

        $lang = $options['lang'] ?? null;
        if ($lang !== null && ! in_array($lang, ['ar', 'en'], true)) {
            throw new ConfigurationException("Profile [{$name}]: lang must be ar or en.");
        }

        $listStyle = $options['list_style'] ?? null;
        if ($listStyle !== null && ! in_array($listStyle, ['h', 'v'], true)) {
            throw new ConfigurationException("Profile [{$name}]: list_style must be h or v.");
        }

        $dueAfter = $options['due_after'] ?? null;
        if ($dueAfter !== null && (! is_int($dueAfter) || $dueAfter < 1)) {
            throw new ConfigurationException("Profile [{$name}]: due_after must be a number of minutes.");
        }

        $codeValidity = $options['code_validity'] ?? self::DUE_DATE;
        if (! in_array($codeValidity, [self::DUE_DATE, self::CODE_EXPIRY], true)) {
            throw new ConfigurationException("Profile [{$name}]: code_validity must be due_date or code_expiry.");
        }

        return new self(
            name: $name,
            kind: $kind,
            method: $kind === 'method' ? $method : null,
            lang: $lang,
            listStyle: $listStyle,
            dueAfter: $dueAfter,
            sendEmail: (bool) ($options['send_email'] ?? false),
            sendSms: (bool) ($options['send_sms'] ?? false),
            reuse: (bool) ($options['reuse'] ?? true),
            returnUrls: self::returnUrls($name, $options['return_urls'] ?? [], $allowedHosts),
            codeValidity: $codeValidity,
        );
    }

    /**
     * @param  list<string>  $allowedHosts
     * @return array<string, string>
     */
    private static function returnUrls(string $name, mixed $urls, array $allowedHosts): array
    {
        if (! is_array($urls)) {
            throw new ConfigurationException("Profile [{$name}]: return_urls must be an array.");
        }

        $unknown = array_diff(array_keys($urls), self::RETURN_URLS);
        if ($unknown !== []) {
            throw new ConfigurationException("Profile [{$name}]: unknown return URLs ".implode(', ', $unknown).'.');
        }

        $clean = [];
        foreach ($urls as $key => $url) {
            if ($url === null) {
                continue;
            }

            $host = is_string($url) && RedirectionUrls::isHttps($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : null;

            if ($host === null || ! in_array($host, $allowedHosts, true)) {
                throw new InvalidRequestException("Profile [{$name}]: the {$key} URL must be https on an allowed host (fawaterk.return_url_hosts).");
            }

            $clean[$key] = $url;
        }

        return $clean;
    }
}

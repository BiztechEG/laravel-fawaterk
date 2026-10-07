<?php

namespace BiztechEG\Fawaterk\Checkout;

use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * How a checkout is asked for: which profile, for what purpose, by whom.
 * It works the same from customer flows, admin screens and APIs.
 */
final class CheckoutContext
{
    private const NAME = '/^[A-Za-z0-9_.:-]{1,64}$/D';

    private const OVERRIDES = ['lang', 'due_after', 'return_urls', 'send_email', 'send_sms', 'list_style', 'reuse', 'code_validity'];

    /**
     * @param  string|null  $profile  a name from fawaterk.profiles; null = fawaterk.default_profile
     * @param  string  $purpose  several payments per payable need different purposes (deposit, balance, …)
     * @param  array<string, mixed>  $inputs  extra inputs for your toFawaterkCheckout()
     * @param  array<string, mixed>  $overrides  per-call profile options: lang, due_after, return_urls, send_email, send_sms, list_style, reuse, code_validity
     */
    public function __construct(
        public readonly ?string $profile = null,
        public readonly string $purpose = 'default',
        public readonly ?string $locale = null,
        public readonly ?Authenticatable $actor = null,
        public readonly array $inputs = [],
        public readonly array $overrides = [],
    ) {
        if ($profile !== null && ! preg_match(self::NAME, $profile)) {
            throw new InvalidRequestException('A profile name may use letters, digits, "_", ".", ":" and "-" (64 at most).');
        }

        if (! preg_match(self::NAME, $purpose)) {
            throw new InvalidRequestException('A purpose may use letters, digits, "_", ".", ":" and "-" (64 at most).');
        }

        $unknown = array_diff(array_keys($overrides), self::OVERRIDES);

        if ($unknown !== []) {
            throw new InvalidRequestException('Unknown checkout overrides: '.implode(', ', $unknown).'.');
        }
    }

    public static function profile(string $profile, string $purpose = 'default'): self
    {
        return new self($profile, $purpose);
    }
}

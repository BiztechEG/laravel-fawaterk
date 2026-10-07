<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\InvalidRequestException;
use DateTimeInterface;

/**
 * The body of POST /api/v3/createTransaction, validated before it is sent.
 *
 * Version 1.0 deliberately has no taxData / discountData: they change the total
 * the customer pays, and the package must be able to predict that total to
 * verify it. Put taxes and discounts into your own cart total instead.
 */
final class CreateTransaction
{
    public const CURRENCIES = ['EGP'];

    /**
     * @param  list<CartItem>  $cartItems
     * @param  array<string, mixed>|null  $payLoad  opaque data echoed back by Fawaterk (never trusted)
     */
    public function __construct(
        public readonly int $cartTotalMinor,
        public readonly Customer $customer,
        public readonly array $cartItems,
        public readonly string $currency = 'EGP',
        public readonly ?RedirectionUrls $redirectionUrls = null,
        public readonly ?array $payLoad = null,
        public readonly ?DateTimeInterface $dueDate = null,
        public readonly ?string $lang = null,
        public readonly ?int $paymentMethodId = null,
        public readonly ?bool $redirectOption = null,
        public readonly ?string $mobileWalletNumber = null,
        public readonly bool $sendEmail = false,
        public readonly bool $sendSms = false,
        public readonly ?string $listStyle = null,
    ) {
        $this->validate();
    }

    /**
     * A copy with some fields changed (validated again).
     *
     * @param  array<string, mixed>  $changes  constructor argument name => value
     */
    public function with(array $changes): self
    {
        $current = [
            'cartTotalMinor' => $this->cartTotalMinor,
            'customer' => $this->customer,
            'cartItems' => $this->cartItems,
            'currency' => $this->currency,
            'redirectionUrls' => $this->redirectionUrls,
            'payLoad' => $this->payLoad,
            'dueDate' => $this->dueDate,
            'lang' => $this->lang,
            'paymentMethodId' => $this->paymentMethodId,
            'redirectOption' => $this->redirectOption,
            'mobileWalletNumber' => $this->mobileWalletNumber,
            'sendEmail' => $this->sendEmail,
            'sendSms' => $this->sendSms,
            'listStyle' => $this->listStyle,
        ];

        $unknown = array_diff_key($changes, $current);

        if ($unknown !== []) {
            throw new InvalidRequestException('Unknown CreateTransaction fields: '.implode(', ', array_keys($unknown)).'.');
        }

        return new self(...array_merge($current, $changes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'cartTotal' => Money::toApiNumber($this->cartTotalMinor),
            'currency' => $this->currency,
            'customer' => $this->customer->toPayload(),
            'cartItems' => array_map(fn (CartItem $item) => $item->toPayload(), $this->cartItems),
            // Sent explicitly: Fawaterk documents no default for these.
            'sendEmail' => $this->sendEmail,
            'sendSMS' => $this->sendSms,
        ];

        if ($this->redirectionUrls !== null && $this->redirectionUrls->toPayload() !== []) {
            $payload['redirectionUrls'] = $this->redirectionUrls->toPayload();
        }

        if ($this->payLoad !== null) {
            $payload['pay_load'] = $this->payLoad;
        }

        if ($this->dueDate !== null) {
            $payload['due_date'] = $this->dueDate->format(DateTimeInterface::ATOM);
        }

        if ($this->lang !== null) {
            $payload['lang'] = $this->lang;
        }

        if ($this->paymentMethodId !== null) {
            $payload['payment_method_id'] = $this->paymentMethodId;
        }

        if ($this->redirectOption !== null) {
            $payload['redirectOption'] = $this->redirectOption;
        }

        if ($this->mobileWalletNumber !== null) {
            $payload['mobileWalletNumber'] = $this->mobileWalletNumber;
        }

        if ($this->listStyle !== null) {
            $payload['list_style'] = $this->listStyle;
        }

        return $payload;
    }

    private function validate(): void
    {
        if ($this->cartTotalMinor <= 0) {
            throw new InvalidRequestException('The cart total must be above zero.');
        }

        if (! in_array($this->currency, self::CURRENCIES, true)) {
            throw new InvalidRequestException('Only EGP is supported in this version.');
        }

        if ($this->cartItems === [] || ! array_is_list($this->cartItems)) {
            throw new InvalidRequestException('At least one cart item is required.');
        }

        $sum = 0;
        foreach ($this->cartItems as $item) {
            if (! $item instanceof CartItem) {
                throw new InvalidRequestException('Cart items must be CartItem objects.');
            }
            $sum += $item->totalMinor();
        }

        if ($sum !== $this->cartTotalMinor) {
            throw new InvalidRequestException('The cart total must equal the sum of the cart items.');
        }

        if ($this->lang !== null && ! in_array($this->lang, ['ar', 'en'], true)) {
            throw new InvalidRequestException('lang must be "ar" or "en".');
        }

        if ($this->listStyle !== null && ! in_array($this->listStyle, ['h', 'v'], true)) {
            throw new InvalidRequestException('list_style must be "h" or "v".');
        }

        if ($this->paymentMethodId !== null && $this->paymentMethodId <= 0) {
            throw new InvalidRequestException('payment_method_id must be a positive id.');
        }

        if ($this->mobileWalletNumber !== null && ! preg_match('/^[0-9]{8,15}$/D', $this->mobileWalletNumber)) {
            throw new InvalidRequestException('The wallet number must contain digits only.');
        }

        if ($this->payLoad !== null) {
            $encoded = json_encode($this->payLoad);

            if ($encoded === false || strlen($encoded) > 2048) {
                throw new InvalidRequestException('pay_load must be JSON-encodable and at most 2 KB.');
            }
        }
    }
}

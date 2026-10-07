<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;

/**
 * The answer to getTransactionData: the only source the package trusts for
 * "is it paid, and how much".
 */
final class TransactionData
{
    /**
     * @param  list<string>  $references  provider references seen in transaction_history
     */
    public function __construct(
        public readonly string $intentKey,
        public readonly int $transactionId,
        public readonly bool $paid,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly ?int $commissionMinor,
        public readonly ?string $paymentMethod,
        public readonly ?string $statusText,
        public readonly ?string $paidAt,
        public readonly array $references = [],
    ) {}

    /**
     * @param  array<string, mixed>  $response  the decoded JSON body
     */
    public static function fromResponse(array $response): self
    {
        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new UnexpectedResponseException('Fawaterk returned no transaction data.');
        }

        $intentKey = IntentKey::normalize($data['intent_key'] ?? null);

        if ($intentKey === null) {
            throw new UnexpectedResponseException('Fawaterk returned no valid intent key.');
        }

        if (! array_key_exists('total', $data)) {
            throw new UnexpectedResponseException('Fawaterk returned no transaction total.');
        }

        $commission = $data['commission'] ?? null;

        return new self(
            intentKey: $intentKey,
            transactionId: self::transactionId($data['transaction_id'] ?? 0),
            paid: self::paid($data['paid'] ?? null),
            totalMinor: Money::fromApiNumber($data['total']),
            currency: is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : '',
            commissionMinor: $commission === null ? null : Money::fromApiNumber($commission),
            paymentMethod: is_string($data['payment_method'] ?? null) ? $data['payment_method'] : null,
            statusText: is_string($data['status_text'] ?? null) ? $data['status_text'] : null,
            paidAt: is_string($data['paid_at'] ?? null) ? $data['paid_at'] : null,
            references: self::references($data['transaction_history'] ?? []),
        );
    }

    /**
     * `paid` is the only paid signal. It must be exactly 0 or 1 (or their
     * string / boolean forms); anything else is an unexpected answer.
     */
    private static function paid(mixed $value): bool
    {
        return match (true) {
            $value === 1, $value === '1', $value === true => true,
            $value === 0, $value === '0', $value === false => false,
            default => throw new UnexpectedResponseException('Fawaterk returned an unreadable paid flag.'),
        };
    }

    private static function transactionId(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if ($value === null) {
            return 0;
        }

        throw new UnexpectedResponseException('Fawaterk returned an unreadable transaction id.');
    }

    /**
     * @return list<string>
     */
    private static function references(mixed $history): array
    {
        if (! is_array($history)) {
            return [];
        }

        $references = [];
        foreach ($history as $entry) {
            $reference = is_array($entry) ? ($entry['reference'] ?? null) : null;

            if ((is_string($reference) || is_int($reference)) && (string) $reference !== '') {
                $references[] = (string) $reference;
            }
        }

        return array_values(array_unique($references));
    }
}

<?php

namespace BiztechEG\Fawaterk\Data;

use BiztechEG\Fawaterk\Exceptions\UnexpectedResponseException;

/**
 * One page (10 entries) of POST /api/v3/refund/index.
 *
 * An entry that cannot be read (no ids, an amount that is not money) is
 * skipped and counted in $skipped, so one bad entry never blocks the refunds
 * around it. A refund skipped this way is not confirmed, and its payment is
 * flagged refund_unverified when the watch window ends.
 */
final class RefundPage
{
    /**
     * @param  list<RefundItem>  $items
     */
    public function __construct(
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly array $items,
        public readonly int $skipped = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $response  the decoded JSON body
     */
    public static function fromResponse(array $response): self
    {
        // Laravel's paginator shape, either at the top level or under "data".
        $page = isset($response['current_page']) ? $response : ($response['data'] ?? null);

        if (! is_array($page) || ! is_array($page['data'] ?? null)) {
            throw new UnexpectedResponseException('Fawaterk returned an unreadable refund list.');
        }

        $items = [];
        $skipped = 0;

        foreach ($page['data'] as $item) {
            try {
                $items[] = RefundItem::fromArray(is_array($item) ? $item : []);
            } catch (UnexpectedResponseException) {
                $skipped++;
            }
        }

        return new self(
            currentPage: (int) ($page['current_page'] ?? 1),
            lastPage: (int) ($page['last_page'] ?? 1),
            items: $items,
            skipped: $skipped,
        );
    }

    public function hasMore(): bool
    {
        return $this->currentPage < $this->lastPage;
    }
}

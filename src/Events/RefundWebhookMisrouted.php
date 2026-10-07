<?php

namespace BiztechEG\Fawaterk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A correctly signed refund webhook reached another webhook URL ($receivedAt: "paid", "failed" or "cancel"), so
 * the dashboard's Refund field most likely holds the wrong URL. The refund was not applied: reconcile's daily read
 * of the refund list counts it. Raised once per refund.
 */
final class RefundWebhookMisrouted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $receivedAt,
        public readonly int $transactionId,
        public readonly string $amount,
        public readonly string $currency,
    ) {}
}

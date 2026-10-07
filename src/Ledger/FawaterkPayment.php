<?php

namespace BiztechEG\Fawaterk\Ledger;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * One payment attempt in the ledger. It holds no customer data.
 *
 * Change rows only through the package (checkout, webhooks, reconcile) and
 * markFulfilled(); a status written by hand skips every check.
 *
 * @property int $id
 * @property string $uuid
 * @property string $account
 * @property string $environment
 * @property string $payable_type
 * @property int|string $payable_id
 * @property string $purpose
 * @property string $profile
 * @property string|null $intent_key
 * @property int|null $fawaterk_transaction_id
 * @property int|null $payment_method_id
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $paid_amount_minor
 * @property int|null $expected_amount_minor
 * @property int $refunded_amount_minor
 * @property PaymentStatus $status
 * @property string|null $failure_reason
 * @property string $order_fingerprint
 * @property array<string, string>|null $flags
 * @property array<int|string, int>|null $refund_ids verified refund id => amount in minor units
 * @property list<array{key: string, until: string, known: list<string>, report?: bool}>|null $refund_pending refund webhooks waiting for the refund list
 * @property list<string>|null $refund_unannounced refund ids the daily list scan counted with no webhook; a later webhook of the same amount uses one
 * @property CarbonInterface|null $refund_watch_until
 * @property CarbonInterface|null $next_refund_check_at
 * @property CarbonInterface|null $next_alert_at
 * @property string|null $checkout_url
 * @property string|null $reference
 * @property CarbonInterface|null $reference_expires_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $last_checked_at
 * @property CarbonInterface|null $next_check_at
 * @property CarbonInterface|null $fulfilled_at
 * @property CarbonInterface|null $next_dispatch_at
 * @property int $fulfil_attempts
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class FawaterkPayment extends Model
{
    protected $guarded = [];

    protected $hidden = ['checkout_url', 'intent_key', 'order_fingerprint'];

    protected $casts = [
        'status' => PaymentStatus::class,
        'flags' => 'array',
        'refund_ids' => 'array',
        'refund_pending' => 'array',
        'refund_unannounced' => 'array',
        'refund_watch_until' => 'datetime',
        'next_refund_check_at' => 'datetime',
        'next_alert_at' => 'datetime',
        'fawaterk_transaction_id' => 'integer',
        'payment_method_id' => 'integer',
        'amount_minor' => 'integer',
        'paid_amount_minor' => 'integer',
        'expected_amount_minor' => 'integer',
        'refunded_amount_minor' => 'integer',
        'fulfil_attempts' => 'integer',
        'reference_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'next_dispatch_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (FawaterkPayment $payment) {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    public function getConnectionName()
    {
        return Ledger::connection() ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return Ledger::table('payments');
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasFlag(Flag $flag): bool
    {
        return array_key_exists($flag->value, $this->flags ?? []);
    }

    public function hasBlockingFlag(): bool
    {
        foreach (array_keys($this->flags ?? []) as $flag) {
            if (Flag::tryFrom($flag)?->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sets the flag in memory; the caller saves.
     */
    public function addFlag(Flag $flag): void
    {
        $flags = $this->flags ?? [];
        $flags[$flag->value] ??= Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');
        $this->flags = $flags;
    }

    public function removeFlag(Flag $flag): void
    {
        $flags = $this->flags ?? [];
        unset($flags[$flag->value]);
        $this->flags = $flags === [] ? null : $flags;
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    /**
     * Record that your app delivered what this payment bought. Safe to call more
     * than once. Until it is called, PaymentPaid is sent again by reconcile.
     */
    public function markFulfilled(): void
    {
        if (! $this->exists) {
            throw new LogicException('Only a stored payment can be marked fulfilled.');
        }

        app(PaymentRecorder::class)->markFulfilled($this);
    }

    /**
     * Deliver exactly once: the callback runs under this payment's row lock,
     * in one database transaction with fulfilled_at. Returns false, without
     * running it, when the payment is already fulfilled.
     *
     * Writes the callback makes on the ledger's connection commit or roll back
     * together with fulfilled_at. Anything else (mail, HTTP, another database)
     * may run again if the transaction is retried or fails.
     *
     * @param  Closure(FawaterkPayment): mixed  $callback
     *
     * @throws LogicException when the payment is not paid or has a blocking flag
     */
    public function fulfilOnce(Closure $callback): bool
    {
        if (! $this->exists) {
            throw new LogicException('Only a stored payment can be fulfilled.');
        }

        return app(PaymentRecorder::class)->fulfilOnce($this, $callback);
    }
}

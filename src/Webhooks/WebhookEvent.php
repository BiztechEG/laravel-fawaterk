<?php

namespace BiztechEG\Fawaterk\Webhooks;

use BiztechEG\Fawaterk\Ledger\Ledger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Webhook log: metadata only, never a body. The intent key is stored only
 * when the signature was verified.
 *
 * @property int $id
 * @property string $account
 * @property string $environment
 * @property string $type
 * @property string $outcome
 * @property string|null $intent_key
 * @property string|null $dedupe_key
 * @property Carbon|null $created_at
 */
class WebhookEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getConnectionName()
    {
        return Ledger::connection() ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return Ledger::table('webhook_events');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', Carbon::now()->subDays(max(1, (int) config('fawaterk.webhook_log_days', 30))));
    }

    /**
     * Whether this webhook was already handled. Read from the primary: a
     * lagging replica would let a replay through.
     */
    public static function seen(WebhookType $type, string $dedupeKey, string $account, string $environment, string $outcome = 'accepted'): bool
    {
        return static::query()
            ->useWritePdo()
            ->where('account', $account)
            ->where('environment', $environment)
            ->where('type', $type->value)
            ->where('dedupe_key', $dedupeKey)
            ->where('outcome', $outcome)
            ->exists();
    }
}

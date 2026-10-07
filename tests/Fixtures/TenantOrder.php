<?php

namespace BiztechEG\Fawaterk\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

/**
 * An order hidden by a global scope outside its tenant's requests, as in
 * multi-tenant apps: webhooks and the scheduler run with no tenant.
 */
class TenantOrder extends Order
{
    protected $table = 'orders';

    public static ?int $currentUser = null;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where('user_id', static::$currentUser ?? -1));
    }
}

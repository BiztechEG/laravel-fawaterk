<?php

namespace BiztechEG\Fawaterk\Tests\Fixtures;

use Illuminate\Support\Str;

class UlidOrder extends KeyedOrder
{
    public static function newKey(): string
    {
        return strtolower((string) Str::ulid());
    }
}

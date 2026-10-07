<?php

namespace BiztechEG\Fawaterk\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftOrder extends Order
{
    use SoftDeletes;

    protected $table = 'orders';
}

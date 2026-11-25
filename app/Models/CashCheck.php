<?php

namespace App\Models;

use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;

class CashCheck extends Model
{
    use Cancellable;

    // Written only by CashService.
    protected $guarded = [];

    protected $casts = [
        'opening' => 'integer', 'expected' => 'integer', 'put_in' => 'integer', 'taken_out' => 'integer', 'counted' => 'integer', 'cancelled_at' => 'datetime',
    ];
}

<?php

namespace App\Models;

use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use Cancellable;

    // Written only by ExpenseService.
    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'cancelled_at' => 'datetime'];
}

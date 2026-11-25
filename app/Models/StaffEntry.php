<?php

namespace App\Models;

use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;

class StaffEntry extends Model
{
    use Cancellable;

    const TYPES = ['payment' => 'Salary paid', 'bonus' => 'Bonus', 'deduction' => 'Deduction'];

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'cancelled_at' => 'datetime'];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}

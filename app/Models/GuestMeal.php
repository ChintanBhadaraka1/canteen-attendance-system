<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GuestMeal extends Model
{
    // Written only by BillingService.
    protected $guarded = [];

    protected $casts = [
        'plates' => 'integer', 'price' => 'integer', 'extra_amount' => 'integer', 'amount' => 'integer', 'cancelled_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }

    public function extras()
    {
        return $this->hasMany(GuestMealExtra::class)->orderBy('id');
    }

    public function scopeNotCancelled($query)
    {
        return $query->whereNull('cancelled_at');
    }

    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    // "2 guest plates of Lunch", without "guest" for a walk-in.
    public function label(): string
    {
        return $this->plates . ($this->isCard() ? ' guest ' : ' ') . Str::plural('plate', $this->plates) . " of {$this->meal_name}";
    }

    public function methodName(): string
    {
        return $this->method === 'upi' ? 'UPI' : 'cash';
    }

    /**
     * One row for the lists. Load extras first.
     */
    public function row(): array
    {
        return $this->only('id', 'type', 'date', 'plates', 'amount', 'guest_name', 'method') + [
            'meal' => $this->meal_name,
            'extras' => $this->extras->map(fn ($row) => ['name' => $row->name, 'quantity' => $row->quantity, 'total' => $row->quantity * $row->price])->all(),
            'status' => $this->cancelled_at !== null ? 'cancelled' : ($this->is_paid === '1' ? 'paid' : 'unpaid'),
            'reason' => $this->cancel_reason,
        ];
    }
}

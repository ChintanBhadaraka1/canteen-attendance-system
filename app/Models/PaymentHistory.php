<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentHistory extends Model
{
    protected $fillable= [
        'student_id',
        'amount',
        'payment_date',
        'type',
        'month_name',
        'before_amount',
        'before_pending',
        'before_advance',
        'created_by',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    protected $casts = ['cancelled_at' => 'datetime'];

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }

    // Only the student's latest payment, and only one saved with the bill it settled.
    public function isCancellable(): bool
    {
        return $this->cancelled_at === null
            && $this->before_amount !== null
            && ! static::active()->where('student_id', $this->student_id)->where('id', '>', $this->id)->exists();
    }
}

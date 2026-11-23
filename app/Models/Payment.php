<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    // Above zero is due, below zero is advance.
    const BALANCE = '(payments.amount + payments.pending_amount - payments.advance_amount)';

    protected $fillable = [
        'student_id',
        'amount',
        'pending_amount',
        'advance_amount',
        'start_date',
        'end_date'
    ];

    public function getBalanceAttribute(): int
    {
        return $this->amount + $this->pending_amount - $this->advance_amount;
    }

    /**
     * Relationship: StudentAttendance belongsTo a User (student)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }
    
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'amount',
        'pending_amount',
        'advance_amount',
        'start_date',
        'end_date'
    ];

    /**
     * Relationship: StudentAttendance belongsTo a User (student)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
    
}

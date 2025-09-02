<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class StudentAttendance extends Model
{
    use HasFactory;

    protected $fillable= [
        'student_id',
        'meal_id',
        'amount',
        'extra_amount',
        'extra_meal_id',
        'date',
        'is_paid'
    ];

       /**
     * Relationship: StudentAttendance belongsTo a User (student)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * Relationship: StudentAttendance belongsTo a MealPrice (meal)
     * Only selecting 'id' and 'name' to optimize query (optional)
     */
    public function meal()
    {
        return $this->belongsTo(MealPrice::class, 'meal_id')->select('id', 'name');
    }
}

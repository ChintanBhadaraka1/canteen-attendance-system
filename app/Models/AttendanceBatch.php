<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceBatch extends Model
{
    protected $guarded = [];

    protected $casts = ['undone_at' => 'datetime'];

    public function entries()
    {
        return $this->hasMany(StudentAttendance::class, 'batch_id');
    }

    public function meal()
    {
        return $this->belongsTo(MealPrice::class, 'meal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}

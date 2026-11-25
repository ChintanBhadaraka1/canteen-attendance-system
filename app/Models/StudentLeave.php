<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentLeave extends Model
{
    protected $fillable = ['student_id', 'from_date', 'to_date', 'reason'];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }

    public function scopeDuring($query, string $date)
    {
        return $query->where('from_date', '<=', $date)->where('to_date', '>=', $date);
    }
}

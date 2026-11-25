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
        'is_paid',
        'payment_history_id',
        'batch_id',
    ];

       /**
     * Relationship: StudentAttendance belongsTo a User (student)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'student_id')->withTrashed();
    }

    /**
     * Relationship: StudentAttendance belongsTo a MealPrice (meal)
     * Only selecting 'id' and 'name' to optimize query (optional)
     */
    public function meal()
    {
        return $this->belongsTo(MealPrice::class, 'meal_id')->select('id', 'name');
    }

    public function extras()
    {
        return $this->hasMany(AttendanceExtra::class, 'attendance_id')->orderBy('id');
    }

    /**
     * Extra items of this entry. Old entries kept only the IDs, so their quantity and total are null.
     *
     * @param  \Illuminate\Support\Collection  $menuNames  menu id => name
     */
    public function extraItems($menuNames): array
    {
        if ($this->extras->isNotEmpty()) {
            return $this->extras->map(fn ($row) => ['name' => $row->name, 'quantity' => $row->quantity, 'total' => $row->quantity * $row->price])->all();
        }

        return $menuNames->only(json_decode($this->extra_meal_id ?? '', true) ?: [])->values()
            ->map(fn ($name) => ['name' => $name, 'quantity' => null, 'total' => null])->all();
    }
}

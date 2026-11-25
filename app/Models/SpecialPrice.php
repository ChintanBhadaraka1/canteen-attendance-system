<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpecialPrice extends Model
{
    protected $fillable = ['meal_id', 'from_date', 'to_date', 'price', 'reason'];

    protected $casts = ['price' => 'integer'];

    public function scopeBetween($query, string $from, string $to)
    {
        return $query->where('from_date', '<=', $to)->where('to_date', '>=', $from);
    }

    // Meals already marked on these dates; they keep their own price.
    public function marked(): int
    {
        return StudentAttendance::where('meal_id', $this->meal_id)->whereBetween('date', [$this->from_date, $this->to_date])->count()
            + (int) GuestMeal::notCancelled()->where('meal_id', $this->meal_id)->whereBetween('date', [$this->from_date, $this->to_date])->sum('plates');
    }
}

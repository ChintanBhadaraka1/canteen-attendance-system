<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceExtra extends Model
{
    public $timestamps = false;

    protected $fillable = ['attendance_id', 'menu_id', 'name', 'quantity', 'price'];

    protected $casts = ['quantity' => 'integer', 'price' => 'integer'];
}

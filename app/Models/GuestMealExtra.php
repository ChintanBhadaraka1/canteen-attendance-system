<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestMealExtra extends Model
{
    public $timestamps = false;

    protected $fillable = ['guest_meal_id', 'menu_id', 'name', 'quantity', 'price'];

    protected $casts = ['quantity' => 'integer', 'price' => 'integer'];
}

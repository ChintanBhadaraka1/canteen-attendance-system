<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Menus extends Model
{
    use HasFactory;

    protected $table= "menus";

    protected $fillable = [
        'name',
        'slug',
        'is_extra',
        'price',
        'images',
        'food_type_id',
        'category_id',
        'description',
        'available',
    ];

    protected $attributes = ['available' => true];

    protected $casts = ['available' => 'boolean', 'food_type_id' => 'integer', 'category_id' => 'integer'];

    public function foodType()
    {
        return $this->belongsTo(MenuOption::class, 'food_type_id');
    }

    public function category()
    {
        return $this->belongsTo(MenuOption::class, 'category_id');
    }

    public function scopeOffered($query)
    {
        return $query->where('available', true);
    }

    // Items that can be charged on top of a meal.
    public function scopeExtras($query)
    {
        return $query->offered()->where('is_extra', '1');
    }
}

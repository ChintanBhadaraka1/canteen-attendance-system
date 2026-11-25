<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuPlan extends Model
{
    protected $fillable = ['meal_id', 'weekday', 'starts_on', 'ends_on', 'closed', 'note'];

    protected $attributes = ['closed' => false];

    protected $casts = ['closed' => 'boolean'];

    public function meal()
    {
        return $this->belongsTo(MealPrice::class, 'meal_id');
    }

    public function items()
    {
        return $this->belongsToMany(Menus::class, 'menu_plan_items', 'menu_plan_id', 'menu_id')
            ->withPivot('position')->orderByPivot('position')->orderBy('menus.id');
    }
}

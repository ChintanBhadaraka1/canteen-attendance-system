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
    ];
}

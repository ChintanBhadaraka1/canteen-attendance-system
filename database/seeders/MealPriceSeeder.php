<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\MealPrice;
use Illuminate\Support\Str;


class MealPriceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
       
        $mealPriceData[] = [
                'name'=>"Break Fast",
                'slug'=>Str::slug("Break Fast"),
                'price'=>30
        ];

        $mealPriceData[] = [
                'name'=>"Lunch",
                'slug'=>Str::slug("Lunch"),
                'price'=>70
        ];

        $mealPriceData[] = [
                'name'=>"Dinner",
                'slug'=>Str::slug("Dinner"),
                'price'=>100
        ];

        foreach ($mealPriceData as  $meal) {
            
            MealPrice::create($meal);
        }

        
    }
}

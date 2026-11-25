<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_options', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('name', 30);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['kind', 'name']);
        });

        $options = [
            'food_type' => ['Veg', 'Jain'],
            'category' => ['Dal', 'Sabzi', 'Roti', 'Rice', 'Sweet', 'Drink', 'Other'],
        ];
        foreach ($options as $kind => $names) {
            foreach ($names as $position => $name) {
                DB::table('menu_options')->insert(compact('kind', 'name', 'position') + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_options');
    }
};

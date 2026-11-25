<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained('meal_prices')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('price');
            $table->string('reason', 60);
            $table->timestamps();
        });

        Schema::create('attendance_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('student_attendances')->cascadeOnDelete();
            $table->foreignId('menu_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('price');
            $table->unique(['attendance_id', 'menu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_extras');
        Schema::dropIfExists('special_prices');
    }
};

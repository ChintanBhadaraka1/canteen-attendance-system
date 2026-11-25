<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->foreignId('food_type_id')->nullable()->constrained('menu_options')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('menu_options')->restrictOnDelete();
            $table->string('description', 200)->nullable();
            $table->boolean('available')->default(true);
        });

        Schema::table('meal_prices', function (Blueprint $table) {
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->time('serve_from')->nullable();
            $table->time('serve_to')->nullable();
        });
        DB::table('meal_prices')->update(['position' => DB::raw('id')]);

        Schema::create('menu_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->constrained('meal_prices')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday')->nullable()->comment('1 Monday to 7 Sunday; empty: only starts_on');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('closed')->default(false);
            $table->string('note', 100)->nullable();
            $table->timestamps();
            $table->index(['meal_id', 'weekday', 'starts_on']);
        });

        Schema::create('menu_plan_items', function (Blueprint $table) {
            $table->foreignId('menu_plan_id')->constrained('menu_plans')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['menu_plan_id', 'menu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_plan_items');
        Schema::dropIfExists('menu_plans');
        Schema::table('meal_prices', fn (Blueprint $table) => $table->dropColumn(['active', 'position', 'serve_from', 'serve_to']));
        Schema::table('menus', function (Blueprint $table) {
            $table->dropConstrainedForeignId('food_type_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['description', 'available']);
        });
    }
};

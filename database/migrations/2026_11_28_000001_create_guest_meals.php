<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_meals', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['card', 'walk_in']);
            $table->foreignId('student_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('meal_id')->constrained('meal_prices')->restrictOnDelete();
            $table->string('meal_name');
            $table->date('date')->index();
            $table->unsignedInteger('plates');
            $table->unsignedInteger('price');
            $table->unsignedInteger('extra_amount');
            $table->unsignedInteger('amount');
            $table->string('guest_name', 60)->nullable();
            $table->enum('method', ['cash', 'upi'])->nullable();
            $table->enum('is_paid', ['1', '0'])->default('0');
            $table->foreignId('payment_history_id')->nullable()->constrained('payment_histories')->restrictOnDelete();
            $table->char('token', 36)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 200)->nullable();
            $table->timestamps();
            $table->index(['student_id', 'date']);
            $table->index(['meal_id', 'date']);
        });

        Schema::create('guest_meal_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_meal_id')->constrained('guest_meals')->cascadeOnDelete();
            $table->foreignId('menu_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('price');
            $table->unique(['guest_meal_id', 'menu_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('guest_meals')->exists()) {
            throw new RuntimeException('Guest plates are part of the bills, so this cannot be rolled back. Restore the backup instead.');
        }

        Schema::dropIfExists('guest_meal_extras');
        Schema::dropIfExists('guest_meals');
    }
};

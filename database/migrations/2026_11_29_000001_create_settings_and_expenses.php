<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Gas', 'Rent', 'Electricity', 'Repairs', 'Other'] as $position => $name) {
            DB::table('menu_options')->insert(['kind' => 'expense_type'] + compact('name', 'position') + ['created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->string('value', 200);
        });
        DB::table('app_settings')->insert([
            ['key' => 'module_expenses', 'value' => '1'], ['key' => 'module_staff', 'value' => '0'], ['key' => 'module_cash', 'value' => '0'],
        ]);

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_id')->nullable()->constrained('menu_options')->restrictOnDelete();
            $table->string('type_name', 30);
            $table->date('date')->index();
            $table->bigInteger('amount');
            $table->enum('method', ['cash', 'upi', 'bank']);
            $table->string('paid_to', 60)->nullable();
            $table->string('note', 200)->nullable();
            $table->char('token', 36)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('app_settings');
        DB::table('menu_options')->where('kind', 'expense_type')->delete();
    }
};

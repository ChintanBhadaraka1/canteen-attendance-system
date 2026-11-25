<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['Cook', 'Helper', 'Cleaner', 'Other'] as $position => $name) {
            DB::table('menu_options')->insert(['kind' => 'staff_job'] + compact('name', 'position') + ['created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('phone', 10)->nullable();
            $table->foreignId('job_id')->nullable()->constrained('menu_options')->restrictOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('staff_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_salary_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('from_month');
            $table->bigInteger('amount');
            $table->timestamps();
            $table->unique(['staff_id', 'from_month']);
        });

        Schema::create('staff_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->enum('type', ['payment', 'bonus', 'deduction']);
            $table->date('month');
            $table->date('date')->index();
            $table->bigInteger('amount');
            $table->enum('method', ['cash', 'upi', 'bank'])->nullable();
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
        Schema::dropIfExists('staff_entries');
        Schema::dropIfExists('staff_salary_rates');
        Schema::dropIfExists('staff_periods');
        Schema::dropIfExists('staff');
        DB::table('menu_options')->where('kind', 'staff_job')->delete();
    }
};

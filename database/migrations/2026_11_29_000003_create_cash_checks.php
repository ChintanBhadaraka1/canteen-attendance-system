<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_checks', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            foreach (['opening', 'expected', 'put_in', 'taken_out', 'counted'] as $amount) {
                $table->bigInteger($amount);
            }
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
        Schema::dropIfExists('cash_checks');
    }
};

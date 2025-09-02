<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->foreign('student_id')
                ->references('id')->on('users')
                ->onDelete('set null');

            $table->integer('amount')->default(0);
            $table->date('payment_date')->default(DB::raw('CURRENT_DATE'))->index();
            $table->enum('type',['cash','upi'])->default('upi');
            $table->string('month_name')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_histories');
    }
};

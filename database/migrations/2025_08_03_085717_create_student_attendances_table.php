<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateStudentAttendancesTable extends Migration
{
    public function up()
    {
        Schema::create('student_attendances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('student_id')->nullable();
            $table->foreign('student_id')
                ->references('id')->on('users')
                ->onDelete('set null');

            $table->unsignedBigInteger('meal_id')->nullable();
            $table->foreign('meal_id')
                ->references('id')->on('meal_prices')
                ->onDelete('set null');

            $table->string('amount')->nullable();
            $table->string('extra_amount')->nullable();
            $table->text('extra_meal_id')->nullable();

            // Date column with default current_date
            $table->date('date')->default(DB::raw('CURRENT_DATE'));

            $table->timestamps();

            $table->index('date');
            $table->index('student_id');
            $table->index('meal_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('student_attendances');
    }
}

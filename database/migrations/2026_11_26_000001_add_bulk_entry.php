<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Checked before any change: MariaDB cannot roll back schema changes.
        $this->stopOnBadIds();

        Schema::create('attendance_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_id')->nullable()->constrained('meal_prices')->nullOnDelete();
            $table->string('meal_name');
            $table->date('date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name');
            $table->unsignedInteger('count');
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('student_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 100)->nullable();
            $table->timestamps();
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->constrained('attendance_batches')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->date('left_on')->nullable();
            $table->unique('canteen_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['canteen_id']);
            $table->dropColumn('left_on');
        });
        Schema::table('student_attendances', fn (Blueprint $table) => $table->dropConstrainedForeignId('batch_id'));
        Schema::dropIfExists('student_leaves');
        Schema::dropIfExists('attendance_batches');
    }

    private function stopOnBadIds(): void
    {
        $users = DB::table('users')->whereNotNull('canteen_id')->orderBy('id')->get(['id', 'canteen_id'])
            ->groupBy(fn ($user) => mb_strtolower(trim($user->canteen_id)));
        $problems = $users->except('')->filter(fn ($group) => $group->count() > 1)->map(fn ($group) => $group->pluck('id')->implode(', '))->values();

        if ($users->has('')) {
            $problems->push('empty: ' . $users['']->pluck('id')->implode(', '));
        }
        if ($problems->isNotEmpty()) {
            throw new RuntimeException('Canteen IDs must be corrected first. User IDs with the same canteen ID: ' . $problems->implode('; '));
        }
    }
};

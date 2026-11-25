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
        $this->stopOnDuplicates();

        Schema::table('payment_histories', function (Blueprint $table) {
            $table->integer('before_amount')->nullable();
            $table->integer('before_pending')->nullable();
            $table->integer('before_advance')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
        });

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->foreignId('payment_history_id')->nullable()->constrained('payment_histories')->restrictOnDelete();
            $table->unique(['student_id', 'meal_id', 'date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
            $table->unique('student_id');
        });
    }

    /**
     * Reverse the migrations. The bill dates stay nullable: newer bills have none.
     */
    public function down(): void
    {
        // The foreign keys need an ordinary index before the unique one goes.
        foreach (['payments', 'student_attendances'] as $name) {
            if (! Schema::hasIndex($name, ['student_id'], 'index') && ! Schema::hasIndex($name, "{$name}_student_id_index")) {
                Schema::table($name, fn (Blueprint $table) => $table->index('student_id'));
            }
        }

        Schema::table('payments', fn (Blueprint $table) => $table->dropUnique(['student_id']));

        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'meal_id', 'date']);
            $table->dropConstrainedForeignId('payment_history_id');
        });

        Schema::table('payment_histories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['before_amount', 'before_pending', 'before_advance', 'cancelled_at', 'cancel_reason']);
        });
    }

    private function stopOnDuplicates(): void
    {
        $problems = [];
        foreach (['student_attendances' => ['student_id', 'meal_id', 'date'], 'payments' => ['student_id']] as $table => $columns) {
            $groups = DB::table($table)->whereNotNull($columns)->groupBy($columns)->havingRaw('count(*) > 1')->get($columns);
            foreach ($groups as $group) {
                $problems[] = "$table: " . DB::table($table)->where((array) $group)->orderBy('id')->pluck('id')->implode(', ');
            }
        }

        if ($problems) {
            throw new RuntimeException('Duplicate rows must be corrected first. IDs in ' . implode('; ', $problems));
        }
    }
};

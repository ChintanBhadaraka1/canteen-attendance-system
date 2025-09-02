<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('middle_name')->nullable()->after('password');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('collage_name')->nullable()->after('last_name');
            $table->string('canteen_id',20)->nullable()->after('collage_name');
            $table->string('phone_number',12)->nullable()->after('canteen_id');
            $table->text('profile_pic')->nullable()->after('phone_number');
            $table->enum('role',['user','admin'])->defulat('user')->after('profile_pic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'middle_name',
                'last_name',
                'collage_name',
                'collage_id',
                'phone_number',
                'profile_pic',
                'role',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPTIONS = [
        'stock_category' => ['Grain', 'Vegetable', 'Dairy', 'Spice', 'Oil', 'Gas', 'Other'],
        'waste_reason' => ['Spoiled', 'Expired', 'Spilled', 'Other'],
    ];

    public function up(): void
    {
        foreach (self::OPTIONS as $kind => $names) {
            foreach ($names as $position => $name) {
                DB::table('menu_options')->insert(compact('kind', 'name', 'position') + ['created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->foreignId('category_id')->nullable()->constrained('menu_options')->restrictOnDelete();
            $table->enum('unit', ['kg', 'litre', 'piece']);
            $table->bigInteger('low_level')->nullable();
            $table->bigInteger('on_hand')->default(0);
            $table->bigInteger('value')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20);
            $table->bigInteger('size');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['stock_item_id', 'name', 'size']);
        });

        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['opening', 'purchase', 'use', 'waste', 'count', 'cancel']);
            $table->date('date')->index();
            $table->string('note', 200)->nullable();
            $table->char('token', 36)->unique();
            $table->foreignId('shop_id')->nullable()->constrained('menu_options')->restrictOnDelete();
            $table->string('shop_name', 30)->nullable();
            $table->string('bill_number', 40)->nullable();
            $table->enum('method', ['cash', 'upi', 'bank'])->nullable();
            $table->bigInteger('discount')->nullable();
            $table->bigInteger('charges')->nullable();
            $table->bigInteger('total')->nullable();
            $table->string('bill_file')->nullable();
            $table->foreignId('reason_id')->nullable()->constrained('menu_options')->restrictOnDelete();
            $table->string('reason_text', 200)->nullable();
            $table->foreignId('cancels_id')->nullable()->unique()->constrained('stock_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name');
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity');
            $table->bigInteger('cost');
            $table->bigInteger('after_quantity');
            $table->string('entered', 60);
            $table->bigInteger('entered_quantity');
            $table->string('entered_unit', 30);
            $table->bigInteger('unit_size');
            $table->timestamps();
            $table->index(['stock_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_entries');
        Schema::dropIfExists('stock_packs');
        Schema::dropIfExists('stock_items');
        DB::table('menu_options')->whereIn('kind', ['stock_category', 'shop', 'waste_reason'])->delete();
    }
};

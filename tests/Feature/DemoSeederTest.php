<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

test('demo data fills every table and adds up', function () {
    app(DemoSeeder::class)->setContainer(app())->run(35);

    $this->artisan('canteen:check-money')->assertExitCode(0);
    foreach (['users', 'student_leaves', 'student_attendances', 'attendance_extras', 'attendance_batches', 'payments', 'payment_histories',
        'guest_meals', 'guest_meal_extras', 'menus', 'menu_options', 'menu_plans', 'menu_plan_items', 'meal_prices', 'special_prices',
        'stock_items', 'stock_packs', 'stock_entries', 'stock_movements', 'expenses', 'staff', 'staff_periods', 'staff_salary_rates',
        'staff_entries', 'cash_checks', 'activity_logs', 'app_settings'] as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(0, $table);
    }
    foreach (['stock_entries' => ['type', 6], 'staff_entries' => ['type', 3], 'guest_meals' => ['type', 2], 'expenses' => ['method', 3], 'payment_histories' => ['type', 2]] as $table => [$column, $count]) {
        expect(DB::table($table)->distinct()->count($column))->toBe($count, $table);
    }

    $users = User::withTrashed()->count();
    expect(fn () => app(DemoSeeder::class)->run(35))->toThrow(RuntimeException::class, 'already has records');
    expect(User::withTrashed()->count())->toBe($users)->and(auth()->check())->toBeFalse();
});

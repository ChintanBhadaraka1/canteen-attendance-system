<?php

use App\Models\AttendanceBatch;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// separateDatabase() changes the default; RefreshDatabase must roll back the real one.
afterEach(fn () => DB::setDefaultConnection('sqlite'));

function testBatch(array $data): AttendanceBatch
{
    return AttendanceBatch::create($data + ['meal_id' => 2, 'meal_name' => 'Dinner', 'date' => '2026-01-10', 'user_name' => 'Test', 'count' => 1]);
}

test('7.1 seeding twice', function () {
    $this->seed();
    MealPrice::firstWhere('slug', 'lunch')->update(['price' => 90]);
    $this->seed();

    expect(MealPrice::count())->toBe(3)->and(User::where('role', 'admin')->count())->toBe(1);
    expect(MealPrice::firstWhere('slug', 'lunch')->price)->toEqual(90);
});

test('7.2 all migrations roll back and run again', function () {
    separateDatabase();
    $tables = fn () => collect(Schema::getTableListing())->sort()->values()->all();
    $before = $tables();
    Artisan::call('migrate:reset');
    expect(collect($tables())->map(fn ($name) => str($name)->afterLast('.')->value())->diff(['migrations', 'sqlite_sequence'])->all())->toBe([]);
    Artisan::call('migrate');
    expect($tables())->toBe($before);
});

test('7.3 clean data passes', function () {
    moneySetup($this);
    markMeal($this, $this->lunch);
    payBillOf($this, 50);
    markMeal($this, $this->dinner);

    $this->artisan('canteen:check-money')->assertExitCode(0);
});

test('7.3 each problem is found and nothing is changed', function (Closure $break, string $expected) {
    moneySetup($this);
    markMeal($this, $this->lunch);
    payBillOf($this, 50);
    markMeal($this, $this->dinner);
    if (str_starts_with($expected, 'duplicate')) {
        Schema::table('student_attendances', fn ($table) => $table->dropUnique(['student_id', 'meal_id', 'date']));
        Schema::table('payments', fn ($table) => $table->dropUnique(['student_id']));
    }
    $break();
    $rows = fn () => collect(['payments', 'payment_histories', 'student_attendances'])->map(fn ($table) => DB::table($table)->get())->toJson();
    $before = $rows();

    expect(Artisan::call('canteen:check-money'))->toBe(1);
    expect(Artisan::output())->toContain($expected)->not->toContain('Asha');
    expect($rows())->toBe($before);
})->with([
    'sum' => [fn () => Payment::query()->update(['amount' => 1]), 'bill 1: amount 1, unpaid entries 100'],
    'both' => [fn () => Payment::query()->update(['pending_amount' => 20, 'advance_amount' => 5]), 'bill 1: pending and advance'],
    'negative' => [fn () => Payment::query()->update(['pending_amount' => -1]), 'bill 1: negative'],
    'duplicate entry' => [fn () => DB::table('student_attendances')->insert((array) DB::table('student_attendances')->where('id', 1)->select('student_id', 'meal_id', 'amount', 'extra_amount', 'date', 'is_paid', 'payment_history_id')->first()), 'duplicate entries: 1, 3'],
    'duplicate bill' => [fn () => DB::table('payments')->insert((array) DB::table('payments')->select('student_id')->first()), 'duplicate bills: 1, 2'],
    'unpaid but linked' => [fn () => StudentAttendance::whereKey(2)->update(['payment_history_id' => 1]), 'entry 2: unpaid but linked to payment 1'],
    'cancelled' => [fn () => PaymentHistory::query()->update(['cancelled_at' => now()]), 'entry 1: linked to cancelled payment 1'],
    'other student' => [fn () => PaymentHistory::query()->update(['student_id' => makeStudent()->id]), 'entry 1: payment 1 belongs to another student'],
    'no bill' => [fn () => Payment::query()->delete(), 'entry 1: no bill'],
    'undone batch' => [function () {
        $batch = testBatch(['undone_at' => now()]);
        StudentAttendance::whereKey(2)->update(['batch_id' => $batch->id]);
    }, 'entry 2: batch 1 was undone'],
    'batch meal' => [function () {
        $batch = testBatch(['meal_id' => 1]);
        StudentAttendance::whereKey(2)->update(['batch_id' => $batch->id]);
    }, 'entry 2: meal or date differs from batch 1'],
    'batch date' => [function () {
        $batch = testBatch(['date' => '2026-01-09']);
        StudentAttendance::whereKey(2)->update(['batch_id' => $batch->id]);
    }, 'entry 2: meal or date differs from batch 1'],
    'decimal' => [fn () => StudentAttendance::whereKey(1)->update(['amount' => '70.5']), 'entry 1: decimal amount'],
]);

test('7.4 runs before the phase 4 migrations', function () {
    separateDatabase();
    Artisan::call('migrate:rollback', ['--step' => 11]);
    $student = makeStudent();
    $meal = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    StudentAttendance::create(['student_id' => $student->id, 'meal_id' => $meal->id, 'amount' => 70, 'date' => '2026-01-10']);
    makeBill($student, 70);

    $this->artisan('canteen:check-money')->assertExitCode(0);
});

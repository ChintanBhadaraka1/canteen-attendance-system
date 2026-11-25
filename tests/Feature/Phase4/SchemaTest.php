<?php

use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// separateDatabase() changes the default; RefreshDatabase must roll back the real one.
afterEach(fn () => DB::setDefaultConnection('sqlite'));

function entry($student, $meal, string $date = '2026-01-10')
{
    return StudentAttendance::create(['student_id' => $student->id, 'meal_id' => $meal->id, 'amount' => 70, 'date' => $date]);
}

function receipt($student, array $data = []): PaymentHistory
{
    return PaymentHistory::create($data + [
        'student_id' => $student->id, 'amount' => 10, 'payment_date' => '2026-01-10', 'type' => 'cash', 'month_name' => 'January',
        'before_amount' => 10, 'before_pending' => 0, 'before_advance' => 0,
    ]);
}

test('1.1 1.2 one entry per student, meal and date', function () {
    $student = makeStudent();
    $lunch = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    $tea = MealPrice::create(['name' => 'Tea', 'slug' => 'tea', 'price' => 5]);
    entry($student, $lunch);
    entry($student, $lunch, '2026-01-11');
    entry($student, $tea);
    expect(StudentAttendance::count())->toBe(3);

    expect(fn () => entry($student, $lunch))->toThrow(UniqueConstraintViolationException::class);
});

test('1.3 one bill per student', function () {
    $student = makeStudent();
    makeBill($student, 10);

    expect(fn () => makeBill($student, 10))->toThrow(UniqueConstraintViolationException::class);
});

test('1.4 duplicates stop the migration before it changes anything', function () {
    separateDatabase();
    Artisan::call('migrate:rollback', ['--step' => 11]);
    $student = makeStudent();
    $lunch = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    $first = entry($student, $lunch);
    $second = entry($student, $lunch);
    $bills = [makeBill($student, 1)->id, makeBill($student, 2)->id];

    expect(fn () => Artisan::call('migrate'))->toThrow(RuntimeException::class, "{$first->id}, {$second->id}");
    try {
        Artisan::call('migrate');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain(implode(', ', $bills));
    }
    expect(StudentAttendance::count())->toBe(2)->and(Payment::count())->toBe(2);
    expect(Schema::hasColumn('student_attendances', 'payment_history_id'))->toBeFalse();
    expect(Schema::hasTable('activity_logs'))->toBeFalse();
});

test('1.5 phase 4 migrations roll back and run again', function () {
    separateDatabase();
    Artisan::call('migrate:rollback', ['--step' => 11]);
    expect(Schema::hasColumn('payment_histories', 'before_amount'))->toBeFalse();
    Artisan::call('migrate');
    expect(Schema::hasColumn('payment_histories', 'before_amount'))->toBeTrue();
});

test('1.6 which payment can be cancelled', function () {
    $student = makeStudent();
    $old = receipt($student);
    $latest = receipt($student);
    receipt(makeStudent());
    expect($latest->isCancellable())->toBeTrue()->and($old->isCancellable())->toBeFalse();

    $latest->update(['cancelled_at' => now()]);
    expect($latest->isCancellable())->toBeFalse()->and($old->isCancellable())->toBeTrue();
    expect(receipt($student, ['before_amount' => null])->isCancellable())->toBeFalse();
});

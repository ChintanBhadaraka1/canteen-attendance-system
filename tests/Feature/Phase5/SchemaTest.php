<?php

use App\Models\StudentLeave;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// separateDatabase() changes the default; RefreshDatabase must roll back the real one.
afterEach(fn () => DB::setDefaultConnection('sqlite'));

test('1.1 canteen id is unique', function () {
    makeStudent(['canteen_id' => null]);
    makeStudent(['canteen_id' => null]);
    makeStudent(['canteen_id' => 'C1']);

    expect(fn () => makeStudent(['canteen_id' => 'C1']))->toThrow(UniqueConstraintViolationException::class);
});

test('1.2 duplicate ids stop the migration before it changes anything', function () {
    separateDatabase();
    Artisan::call('migrate:rollback', ['--step' => 9]);
    $first = makeStudent(['canteen_id' => 'C1']);
    $second = makeStudent(['canteen_id' => 'c1 ']);
    $second->delete();
    $empty = makeStudent(['canteen_id' => '']);

    try {
        Artisan::call('migrate');
        $message = 'none';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    expect($message)->toContain("{$first->id}, {$second->id}")->toContain("empty: {$empty->id}");
    expect(Schema::hasColumn('users', 'left_on'))->toBeFalse()->and(Schema::hasTable('attendance_batches'))->toBeFalse();
    expect(User::withTrashed()->count())->toBe(3);
});

test('1.3 rolls back and runs again', function () {
    separateDatabase();
    Artisan::call('migrate:rollback', ['--step' => 9]);
    expect(Schema::hasTable('student_leaves'))->toBeFalse()->and(Schema::hasColumn('student_attendances', 'batch_id'))->toBeFalse();
    Artisan::call('migrate');
    expect(Schema::hasTable('student_leaves'))->toBeTrue()->and(Schema::hasColumn('student_attendances', 'batch_id'))->toBeTrue();
});

test('1.4 active students', function () {
    $normal = makeStudent();
    makeStudent(['left_on' => '2026-01-01']);
    makeStudent()->delete();
    makeAdmin();

    expect(User::active()->pluck('id')->all())->toBe([$normal->id]);
    expect((new User)->isFillable('left_on'))->toBeFalse();
});

test('1.5 away dates', function () {
    $student = makeStudent();
    StudentLeave::create(['student_id' => $student->id, 'from_date' => '2026-01-10', 'to_date' => '2026-01-12']);

    expect(array_map(fn ($day) => $student->isAwayOn("2026-01-$day"), ['09', '10', '12', '13']))->toBe([false, true, true, false]);
});

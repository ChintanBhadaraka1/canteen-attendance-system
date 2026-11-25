<?php

use App\Models\ActivityLog;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\StudentAttendance;

beforeEach(fn () => moneySetup($this));

function unchanged(): void
{
    expect(StudentAttendance::count())->toBe(0)->and(Payment::count())->toBe(0);
}

test('2.1 two meals make one bill', function () {
    markMeal($this, $this->lunch);
    markMeal($this, $this->dinner);

    expect(StudentAttendance::count())->toBe(2)->and(Payment::sole()->amount)->toBe(170);
});

test('2.2 2.3 extras are charged by id', function () {
    Menus::create(['name' => 'Curd', 'slug' => 'curd', 'is_extra' => '1', 'price' => 15]);
    $other = Menus::create(['name' => 'Curd', 'slug' => 'curd', 'is_extra' => '1', 'price' => 20]);
    markMeal($this, $this->lunch, ['include_extra' => 'yes', 'extra_items' => [Menus::first()->id => 2, $other->id => 0]]);
    expect(StudentAttendance::sole())->amount->toEqual(70)->extra_amount->toEqual(30);
    expect(Payment::sole()->amount)->toBe(100);

    markMeal($this, $this->dinner, ['include_extra' => 'yes', 'extra_items' => [$other->id => 1]]);
    expect(StudentAttendance::latest('id')->first())->extra_amount->toEqual(20)->extra_meal_id->toBeNull();
});

test('2.4 bad quantities are refused', function ($quantity) {
    $curd = Menus::create(['name' => 'Curd', 'slug' => 'curd', 'is_extra' => '1', 'price' => 15]);
    markMeal($this, $this->lunch, ['include_extra' => 'yes', 'extra_items' => [$curd->id => $quantity]])->assertSessionHasErrors();
    unchanged();
})->with([-1, 100, 1.5, 'x']);

test('2.5 unknown extras are refused', function () {
    $dal = Menus::create(['name' => 'Dal', 'slug' => 'dal', 'price' => 15]);
    foreach ([999, $dal->id, 'curd'] as $key) {
        markMeal($this, $this->lunch, ['include_extra' => 'yes', 'extra_items' => [$key => 1]])
            ->assertSessionHas('error', 'An extra item is no longer available. Please choose again.');
    }
    unchanged();
});

test('2.6 extras are ignored when not asked for', function () {
    $curd = Menus::create(['name' => 'Curd', 'slug' => 'curd', 'is_extra' => '1', 'price' => 15]);
    markMeal($this, $this->lunch, ['extra_items' => [$curd->id => 2]]);

    expect(StudentAttendance::sole()->extra_amount)->toEqual(0)->and(Payment::sole()->amount)->toBe(70);
});

test('2.7 bad requests save nothing', function (Closure $data) {
    markMeal($this, $this->lunch, $data())->assertSessionHasErrors();
    unchanged();
})->with([
    'tomorrow' => [fn () => ['date' => today()->addDay()->toDateString()]],
    'date list' => [fn () => ['date' => ['x']]],
    'other format' => [fn () => ['date' => '10-01-2026']],
    'deleted student' => [function () {
        $student = makeStudent();
        $student->delete();

        return ['user_id' => $student->id];
    }],
    'admin' => [fn () => ['user_id' => makeAdmin()->id]],
    'missing meal' => [fn () => ['meal_id' => 999]],
    'meal list' => [fn () => ['meal_id' => [1]]],
    'student list' => [fn () => ['user_id' => [[1]]]],
    'huge number' => [fn () => ['user_id' => '99999999999999999999']],
]);

test('2.8 duplicate and before the last paid date', function () {
    markMeal($this, $this->lunch);
    markMeal($this, $this->lunch)->assertSessionHas('error', 'Lunch is already marked for this date.');
    payBillOf($this, 70);

    markMeal($this, $this->dinner, ['date' => '2026-01-09'])->assertSessionHas('error', 'This date is already paid, so attendance cannot be added.');
    markMeal($this, $this->dinner)->assertSessionHas('success');
    expect(StudentAttendance::count())->toBe(2)->and(Payment::sole()->amount)->toBe(100);
});

test('2.9 a failure halfway saves nothing', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('SECRET'));
    markMeal($this, $this->lunch)->assertStatus(500);
    unchanged();
});

test('2.10 2.12 delete once', function () {
    $lunch = markMeal($this, $this->lunch, [], true);
    markMeal($this, $this->dinner);
    $this->post('/student-attendance/delete', ['id' => $lunch->id])->assertSessionHas('success');
    $this->post('/student-attendance/delete', ['id' => $lunch->id])->assertSessionHas('error', 'This attendance entry was already deleted.');

    expect(Payment::sole()->amount)->toBe(100)->and(StudentAttendance::sole()->meal_id)->toBe($this->dinner->id);
});

test('2.11 a paid entry cannot be deleted', function () {
    $lunch = markMeal($this, $this->lunch, [], true);
    payBillOf($this, 50);
    $this->post('/student-attendance/delete', ['id' => $lunch->id])
        ->assertSessionHas('error', 'This meal is already paid, so it cannot be deleted. Cancel the payment first.');

    expect(StudentAttendance::count())->toBe(1)->and(Payment::sole())->amount->toBe(0)->pending_amount->toBe(20);
});

test('2.13 a later price change does not change the refund', function () {
    $lunch = markMeal($this, $this->lunch, [], true);
    $this->lunch->update(['price' => 90]);
    $this->post('/student-attendance/delete', ['id' => $lunch->id]);

    expect(Payment::sole()->amount)->toBe(0);
});

test('2.14 an entry of a deleted student', function () {
    $lunch = markMeal($this, $this->lunch, [], true);
    $this->student->delete();
    $this->post('/student-attendance/delete', ['id' => $lunch->id])->assertSessionHas('success');

    expect(StudentAttendance::count())->toBe(0)->and(Payment::sole()->amount)->toBe(0);
});

test('a list of ids deletes nothing', function () {
    markMeal($this, $this->lunch);
    $this->post('/student-attendance/delete', ['id' => ['x']])->assertSessionHas('error');

    expect(StudentAttendance::count())->toBe(1)->and(Payment::sole()->amount)->toBe(70);
});

test('an entry with no student is refused', function () {
    $lunch = markMeal($this, $this->lunch, [], true);
    $lunch->update(['student_id' => null]);
    $this->post('/student-attendance/delete', ['id' => $lunch->id])->assertSessionHas('error');

    expect(StudentAttendance::count())->toBe(1);
});

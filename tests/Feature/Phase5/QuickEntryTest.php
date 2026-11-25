<?php

use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\StudentLeave;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

function quick($test, mixed $id, array $data = [])
{
    return $test->post('/student-attendance/quick', $data + ['canteen_id' => $id, 'meal_id' => $test->lunch->id]);
}

test('4.1 exact id, any case', function (string $id) {
    quick($this, $id)->assertRedirect('/student-attendance/quick?meal=' . $this->lunch->id)
        ->assertSessionHas('marked', fn ($marked) => $marked['message'] === 'Asha Patel marked for Lunch.'
            && $marked['id'] === StudentAttendance::sole()->id && $marked['canteen_id'] === 'C1' && $marked['away'] === false);

    expect(StudentAttendance::sole()->date)->toBe(today()->toDateString())->and(Payment::sole()->amount)->toBe(70);
    $this->get('/student-attendance/quick?meal=' . $this->lunch->id)
        ->assertInertia(fn (Assert $page) => $page->component('Attendance/Quick')->where('flash.marked.name', 'Asha Patel')->has('recent', 1));
})->with(['C1', 'c1', ' C1 ']);

test('4.2 partial text never matches', function () {
    makeStudent(['canteen_id' => 'C11']);
    quick($this, 'C')->assertSessionHasErrors(['canteen_id' => 'No student has canteen ID C.']);
    quick($this, '1')->assertSessionHasErrors('canteen_id');

    expect(StudentAttendance::count())->toBe(0);
});

test('4.3 strange ids', function (mixed $id) {
    quick($this, $id)->assertSessionHasErrors('canteen_id');

    expect(StudentAttendance::count())->toBe(0);
})->with(['C%', '_1', "' OR 1=1", '', '12345678901', [['C1']]]);

test('4.4 unknown, deleted, left, admin', function () {
    makeStudent(['canteen_id' => 'D1'])->delete();
    makeStudent(['name' => 'Zed', 'middle_name' => null, 'last_name' => null, 'canteen_id' => 'L1', 'left_on' => '2026-01-01']);
    $this->admin->forceFill(['canteen_id' => 'A1'])->save();

    foreach (['C9', 'D1', 'A1'] as $id) {
        quick($this, $id)->assertSessionHasErrors(['canteen_id' => "No student has canteen ID $id."]);
    }
    quick($this, 'L1')->assertSessionHasErrors(['canteen_id' => 'Zed has left, so attendance cannot be added.']);
    expect(StudentAttendance::count())->toBe(0);
});

test('4.5 twice', function () {
    quick($this, 'C1');
    quick($this, 'C1')->assertSessionHasErrors(['canteen_id' => 'Asha Patel: Lunch is already marked for this date.']);

    expect(Payment::sole()->amount)->toBe(70);
});

test('4.6 away', function () {
    StudentLeave::create(['student_id' => $this->student->id, 'from_date' => today()->toDateString(), 'to_date' => today()->toDateString()]);
    quick($this, 'C1')->assertSessionHas('marked', fn ($marked) => $marked['away']
        && $marked['message'] === 'Asha Patel marked for Lunch. They are noted as away today.');
});

test('4.7 meal is required', function (mixed $meal) {
    quick($this, 'C1', ['meal_id' => $meal])->assertSessionHasErrors('meal_id');

    expect(StudentAttendance::count())->toBe(0);
})->with([null, 999, 'x', [[1]]]);

test('4.8 4.9 undo returns to the quick page', function () {
    quick($this, 'C1');
    $id = StudentAttendance::sole()->id;
    $this->post('/student-attendance/delete', ['id' => $id, 'return_to' => 'quick', 'meal_id' => $this->lunch->id])
        ->assertRedirect('/student-attendance/quick?meal=' . $this->lunch->id)->assertSessionHas('success');
    expect(StudentAttendance::count())->toBe(0)->and(Payment::sole()->amount)->toBe(0);

    $this->post('/student-attendance/delete', ['id' => $id, 'return_to' => 'quick', 'meal_id' => 'x'])
        ->assertRedirect('/student-attendance/quick')->assertSessionHas('error');
    quick($this, 'C1');
    $this->post('/student-attendance/delete', ['id' => StudentAttendance::sole()->id, 'return_to' => 'https://evil.example'])
        ->assertRedirectContains('/student-attendance/show/');
});

test('4.10 the date is always today', function () {
    quick($this, 'C1', ['date' => '2026-01-01']);

    expect(StudentAttendance::sole()->date)->toBe(today()->toDateString());
});

test('4.11 recent entries', function () {
    foreach (range(1, 11) as $n) {
        makeStudent(['canteen_id' => "R$n"]);
        quick($this, "R$n");
    }
    quick($this, 'R1', ['meal_id' => $this->dinner->id]);
    markMeal($this, $this->lunch);

    $this->get('/student-attendance/quick?meal=' . $this->lunch->id)->assertInertia(fn (Assert $page) => $page
        ->has('recent', 10)->where('recent.0.canteen_id', 'R11')->where('recent.9.canteen_id', 'R2'));
    $this->get('/student-attendance/quick?meal[]=x')->assertInertia(fn (Assert $page) => $page->where('meal', null)->has('recent', 0));
});

test('4.12 admins only', function () {
    $this->actingAs($this->student);
    quick($this, 'C1')->assertForbidden();
    $this->get('/student-attendance/quick')->assertForbidden();
    auth()->logout();
    quick($this, 'C1')->assertRedirect('/login');
    expect(StudentAttendance::count())->toBe(0);
});

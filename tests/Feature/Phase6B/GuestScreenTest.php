<?php

use App\Models\GuestMeal;
use App\Models\StudentAttendance;
use App\Models\StudentLeave;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    moneySetup($this);
    $this->today = today()->toDateString();
});

function guestPage($test, string $query = '')
{
    return $test->get("/student-attendance/guest$query")->assertOk();
}

test('6.1 filled in', function () {
    makeBill($this->student, 0, 50);
    markMeal($this, $this->dinner, ['date' => $this->today]);
    guestPage($this, "?student={$this->student->id}&meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Attendance/Guest')->where('type', 'card')->where('meal', $this->lunch->id)->where('date', $this->today)
        ->where('student.id', $this->student->id)->where('student.due', 150)->where('student.own', [$this->dinner->id])->where('student.away', false)
        ->has('meals', 2)->has('extras', 0)->has('students', 1)->has('token')->has('recent', 0));
    guestPage($this, "?student={$this->student->id}&date=2026-01-10&type=walk_in")->assertInertia(fn (Assert $page) => $page
        ->where('type', 'walk_in')->where('date', '2026-01-10')->where('student.own', []));
});

test('6.2 bad values', function () {
    $left = makeStudent(['left_on' => '2026-01-01']);
    $deleted = makeStudent();
    $deleted->delete();
    foreach ([$left->id, $deleted->id, $this->admin->id, 'x', '999'] as $id) {
        guestPage($this, "?student=$id")->assertInertia(fn (Assert $page) => $page->where('student', null)->where('type', null));
    }
    $this->lunch->update(['active' => false]);
    guestPage($this, "?meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->where('meal', null));
    guestPage($this, '?student[]=1&meal[]=1&type[]=card&date[]=2026-01-10')->assertInertia(fn (Assert $page) => $page
        ->where('student', null)->where('meal', null)->where('type', null)->where('date', $this->today));
    guestPage($this, '?type=x&date=2099-01-01')->assertInertia(fn (Assert $page) => $page->where('type', null)->where('date', $this->today));
});

test('6.3 the same id twice', function () {
    $quick = fn () => $this->post('/student-attendance/quick', ['canteen_id' => 'C1', 'meal_id' => $this->lunch->id]);
    $quick()->assertSessionHas('marked', fn ($marked) => $marked['student_id'] === $this->student->id)->assertSessionMissing('already');
    $quick()->assertSessionHasErrors('canteen_id')->assertSessionHas('already', $this->student->id);
    expect(StudentAttendance::count())->toBe(1)->and(GuestMeal::count())->toBe(0);
    $this->get("/student-attendance/quick?meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->where('flash.already', $this->student->id));
});

test('6.3 other refusals carry no student', function () {
    makeStudent(['canteen_id' => 'L1', 'left_on' => '2026-01-01']);
    $this->post('/student-attendance/quick', ['canteen_id' => 'L1', 'meal_id' => $this->lunch->id])->assertSessionHasErrors()->assertSessionMissing('already');
});

test('6.4 recent', function () {
    foreach (range(1, 6) as $n) {
        cardGuest($this, $this->lunch, ['date' => $this->today]);
        walkIn($this, $this->lunch, ['date' => $this->today, 'plates' => 2]);
    }
    cancelGuest($this, GuestMeal::max('id'));
    cardGuest($this, $this->lunch, ['date' => '2026-01-10']);
    guestPage($this)->assertInertia(fn (Assert $page) => $page->has('recent', 10)
        ->where('recent.0.id', 12)->where('recent.0.status', 'cancelled')->where('recent.0.type', 'walk_in')->where('recent.0.student', null)->where('recent.0.method', 'cash')
        ->where('recent.1.status', 'unpaid')->where('recent.1.student', 'Asha Patel')
        ->where('recent.2.status', 'paid'));
});

test('6.5 away', function () {
    StudentLeave::create(['student_id' => $this->student->id, 'from_date' => '2026-01-10', 'to_date' => '2026-01-10']);
    guestPage($this, "?student={$this->student->id}&date=2026-01-10")->assertInertia(fn (Assert $page) => $page->where('student.away', true));
    cardGuest($this, $this->lunch)->assertSessionHas('success');
});

test('6.6 where it goes back to', function () {
    $outside = ['return_to' => 'https://example.com'];
    cardGuest($this, $this->lunch, $outside)->assertRedirect("/student-attendance/guest?type=card&meal={$this->lunch->id}&date=2026-01-10");
    walkIn($this, $this->lunch, $outside)->assertRedirect("/student-attendance/guest?type=walk_in&meal={$this->lunch->id}&date=2026-01-10");
    $id = GuestMeal::first()->id;
    $this->post("/student-attendance/guest/$id/cancel", $outside + ['reason' => 'Wrong'])->assertRedirect('/student-attendance/guest?type=card&date=2026-01-10');
    cardGuest($this, $this->lunch);
    $this->post('/student-attendance/guest/' . GuestMeal::max('id') . '/cancel', ['reason' => 'Wrong', 'return_to' => 'history', 'from' => '2026-01-01', 'to' => '2026-01-31'])
        ->assertRedirect("/student-attendance/show/{$this->student->id}?from=2026-01-01&to=2026-01-31");
});

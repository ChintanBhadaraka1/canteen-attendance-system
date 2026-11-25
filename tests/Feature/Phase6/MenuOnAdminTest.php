<?php

use App\Models\StudentAttendance;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    moneySetup($this);
});

test('6.1 home', function () {
    planMenu($this, [dish('Dal')]);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where("menu.{$this->lunch->id}.items.0.name", 'Dal')->where("menu.{$this->dinner->id}", null));
    planMenu($this, [], ['meal_id' => $this->dinner->id, 'closed' => true]);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where("menu.{$this->dinner->id}.closed", true));
    $this->dinner->update(['active' => false]);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->missing("menu.{$this->dinner->id}"));
});

test('6.2 6.4 attendance screens', function () {
    special($this, ['from_date' => '2026-11-25', 'to_date' => '2026-11-25']);
    planMenu($this, [dish('Dal')]);
    $id = $this->lunch->id;
    foreach (['/student-attendance', '/student-attendance/bulk', '/student-attendance/quick', "/student-attendance/create/{$this->student->id}"] as $url) {
        $this->get("$url?meal=$id")->assertInertia(fn (Assert $page) => $page
            ->where("menu.$id.items.0.name", 'Dal')->where('meals.0.price', 90)->where('meals.0.reason', 'Diwali'));
    }
    foreach (['/student-attendance/bulk', "/student-attendance/create/{$this->student->id}"] as $url) {
        $this->get("$url?meal=$id&date=2026-11-24")->assertInertia(fn (Assert $page) => $page->where("menu.$id", null)->where('meals.0.price', 70));
    }
});

test('6.3 a closed day can be marked', function () {
    planMenu($this, [], ['closed' => true, 'note' => 'Holiday']);
    $this->get('/student-attendance')->assertInertia(fn (Assert $page) => $page->where("menu.{$this->lunch->id}.closed", true));
    markMeal($this, $this->lunch, ['date' => '2026-11-25']);
    expect(StudentAttendance::count())->toBe(1);
});

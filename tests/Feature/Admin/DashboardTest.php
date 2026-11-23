<?php

use App\Models\MealPrice;
use App\Models\StudentAttendance;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->actingAs(makeAdmin()));

test('home works with no data', function () {
    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->has('meals', 0)->where('students', 0)
        ->where('due', ['total' => 0, 'students' => 0])->has('week', 7)->has('highest', 0));
});

test('home numbers', function () {
    $lunch = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    MealPrice::create(['name' => 'Tea', 'slug' => 'tea', 'price' => 5]);
    $due = makeStudent(['name' => 'Due', 'middle_name' => null, 'last_name' => 'Boy', 'canteen_id' => 'C1']);
    $advance = makeStudent();
    $clear = makeStudent();
    $deleted = makeStudent();
    makeBill($due, 100, 50, 0);
    makeBill($advance, 10, 0, 60);
    makeBill($clear, 0, 0, 0);
    makeBill($deleted, 900, 0, 0);
    $deleted->delete();

    foreach ([0 => 2, 6 => 1, 7 => 1] as $daysAgo => $count) {
        foreach (range(1, $count) as $n) {
            StudentAttendance::create(['student_id' => $due->id, 'meal_id' => $lunch->id, 'amount' => 70, 'date' => today()->subDays($daysAgo)->toDateString()]);
        }
    }

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('meals.0', ['id' => $lunch->id, 'name' => 'Lunch', 'total' => 2])->where('meals.1.total', 0)
        ->where('students', 3)
        ->where('due', ['total' => 150, 'students' => 1])
        ->where('week.0.total', 1)->where('week.0.date', today()->subDays(6)->toDateString())
        ->where('week.1.total', 0)->where('week.6.total', 2)->where('week.6.label', 'Today')
        ->has('highest', 1)->where('highest.0', ['id' => $due->id, 'name' => 'Due Boy', 'canteen_id' => 'C1', 'due' => 150]));
});

test('highest bills are the top five', function () {
    foreach ([10, 60, 30, 50, 20, 40] as $amount) {
        makeBill(makeStudent(), $amount);
    }

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('highest', 5)->where('highest.0.due', 60)->where('highest.4.due', 20));
});

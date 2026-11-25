<?php

use App\Models\ActivityLog;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\SpecialPrice;
use App\Models\StudentAttendance;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-12-08 10:00:00');
    moneySetup($this);
});

test('4.1 price on a date', function () {
    special($this)->assertSessionHas('success');
    expect(array_map(fn ($day) => $this->lunch->priceOn($day), ['2026-12-07', '2026-12-08', '2026-12-10', '2026-12-11']))->toBe([70, 90, 90, 70]);
    expect(MealPrice::pricesOn('2026-12-09')[$this->lunch->id])->toBe(['price' => 90, 'normal' => 70, 'reason' => 'Diwali']);
    expect(MealPrice::pricesOn('2026-12-11')[$this->lunch->id])->toBe(['price' => 70, 'normal' => 70, 'reason' => null]);
});

test('4.2 every way to mark charges it', function () {
    special($this);
    $others = [makeStudent(['canteen_id' => 'C2']), makeStudent(), makeStudent()];
    markMeal($this, $this->lunch, ['date' => '2026-12-08']);
    $this->post('/student-attendance/quick', ['canteen_id' => 'C2', 'meal_id' => $this->lunch->id]);
    markMeal($this, $this->lunch, ['user_id' => $others[1]->id, 'date' => null, 'return_to' => 'list']);
    bulk($this, [$others[2]->id], ['date' => '2026-12-08', 'price' => 90])->assertSessionHas('success');
    expect(StudentAttendance::pluck('amount')->map(fn ($amount) => (int) $amount)->all())->toBe([90, 90, 90, 90]);
    expect(Payment::pluck('amount')->all())->toBe([90, 90, 90, 90]);
});

test('4.3 saved entries keep their price', function () {
    $entry = markMeal($this, $this->lunch, ['date' => '2026-12-08'], true);
    special($this)->assertSessionHas('success', fn ($text) => str_contains($text, '1 meal already marked on these dates keeps its price.'));
    $id = SpecialPrice::sole()->id;
    $this->put("/special-price/$id", ['price' => 95, 'from_date' => '2026-12-08', 'to_date' => '2026-12-10', 'reason' => 'Diwali']);
    expect(SpecialPrice::sole()->price)->toBe(95);
    $this->delete("/special-price/$id")->assertSessionHas('success');
    expect($entry->fresh()->amount)->toEqual(70)->and(Payment::sole()->amount)->toBe(70);
    $this->post('/student-attendance/delete', ['id' => $entry->id]);
    expect(Payment::sole()->amount)->toBe(0);
    expect(ActivityLog::where('action', 'like', 'special%')->orderBy('id')->pluck('action')->all())->toBe(['special_price.added', 'special_price.changed', 'special_price.removed']);
    $this->get('/changes?type=meal')->assertInertia(fn (Assert $page) => $page->has('changes.data', 3));
});

test('4.4 price zero', function () {
    special($this, ['price' => 0]);
    markMeal($this, $this->lunch, ['date' => '2026-12-08']);
    expect(StudentAttendance::sole()->amount)->toEqual(0)->and(Payment::sole()->amount)->toBe(0);
});

test('4.5 bulk at the old price', function () {
    special($this);
    bulk($this, [$this->student->id], ['date' => '2026-12-08'])->assertSessionHas('error');
    expect(StudentAttendance::count())->toBe(0);
});

test('4.6 past dates', function () {
    special($this, ['from_date' => '2026-12-01', 'to_date' => '2026-12-02']);
    $this->lunch->update(['price' => 75]);
    markMeal($this, $this->lunch, ['date' => '2026-12-01']);
    markMeal($this, $this->lunch, ['date' => '2026-12-03']);
    expect(StudentAttendance::orderBy('id')->pluck('amount')->map(fn ($amount) => (int) $amount)->all())->toBe([90, 75]);
});

test('4.7 overlap', function () {
    special($this);
    special($this)->assertSessionHasErrors('from_date');
    special($this, ['from_date' => '2026-12-09', 'to_date' => '2026-12-09'])->assertSessionHasErrors('from_date');
    special($this, ['from_date' => '2026-12-01', 'to_date' => '2026-12-08'])->assertSessionHasErrors(['from_date' => 'Lunch already has a special price from 8 Dec 2026 to 10 Dec 2026 (Diwali).']);
    special($this, ['from_date' => '2026-12-11', 'to_date' => '2026-12-12'])->assertSessionHasNoErrors();
    $this->post("/meal-price/{$this->dinner->id}/special", ['price' => 1, 'from_date' => '2026-12-08', 'to_date' => '2026-12-10', 'reason' => 'Diwali'])->assertSessionHasNoErrors();
    $this->put('/special-price/1', ['price' => 91, 'from_date' => '2026-12-08', 'to_date' => '2026-12-09', 'reason' => 'Diwali'])->assertSessionHasNoErrors();
    expect(SpecialPrice::count())->toBe(3);
});

test('4.8 bad input', function ($data) {
    special($this, $data)->assertSessionHasErrors();
    expect(SpecialPrice::count())->toBe(0);
})->with([
    [['price' => -1]], [['price' => 70.5]], [['price' => 100001]], [['price' => null]], [['to_date' => '2026-12-07']],
    [['to_date' => '2027-12-10']], [['reason' => 'a']], [['reason' => str_repeat('a', 61)]], [['reason' => ['x']]], [['from_date' => ['x']]],
]);

test('4.9 extras keep their own price', function () {
    special($this);
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    markMeal($this, $this->lunch, ['date' => '2026-12-08', 'include_extra' => 'yes', 'extra_items' => [$curd->id => 2]]);
    expect(StudentAttendance::sole())->amount->toEqual(90)->extra_amount->toEqual(30)->and(Payment::sole()->amount)->toBe(120);
});

test('4.10 pages', function () {
    special($this);
    foreach (['/', '/student-attendance', '/student-attendance/quick', '/student-attendance/bulk'] as $url) {
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('meals.0.price', 90)->where('meals.0.reason', 'Diwali'));
    }
    $this->get("/student-attendance/create/{$this->student->id}?date=2026-12-07")->assertInertia(fn (Assert $page) => $page->where('meals.0.price', 70)->where('date', '2026-12-07'));
    $this->get("/student-attendance/create/{$this->student->id}?date=2026-12-09")->assertInertia(fn (Assert $page) => $page->where('date', '2026-12-08'));
    $this->get('/meal-price')->assertInertia(fn (Assert $page) => $page->where('meals.0.price', '70')->where('meals.0.specials.0.price', 90)->where('meals.0.specials.0.marked', 0));
});

test('4.11 an error saves nothing', function () {
    special($this);
    ActivityLog::creating(fn () => throw new Exception('no'));
    special($this, ['from_date' => '2026-12-20', 'to_date' => '2026-12-20'])->assertServerError();
    $this->put('/special-price/1', ['price' => 1, 'from_date' => '2026-12-08', 'to_date' => '2026-12-10', 'reason' => 'Diwali'])->assertServerError();
    $this->delete('/special-price/1')->assertServerError();
    expect(SpecialPrice::sole()->price)->toBe(90);
});

test('4.12 guest and student', function () {
    $routes = [['post', "/meal-price/{$this->lunch->id}/special"], ['put', '/special-price/1'], ['delete', '/special-price/1']];
    $this->actingAs($this->student);
    foreach ($routes as [$method, $url]) {
        $this->$method($url)->assertForbidden();
    }
    auth()->logout();
    foreach ($routes as [$method, $url]) {
        $this->$method($url)->assertRedirect('/login');
    }
});

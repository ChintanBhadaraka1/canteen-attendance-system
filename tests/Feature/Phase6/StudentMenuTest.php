<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    moneySetup($this);
});

test('7.1 landing', function () {
    auth()->logout();
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('menu', 0));
    $this->actingAs($this->admin);
    planMenu($this, [dish('Dal')]);
    planMenu($this, [], ['meal_id' => $this->dinner->id, 'closed' => true, 'note' => 'Holiday']);
    auth()->logout();
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('menu.0.items.0.name', 'Dal')->where('menu.1.closed', true)->where('menu.1.note', 'Holiday'));
});

test('7.2 account', function () {
    planMenu($this, [dish('Dal')], ['repeat' => 'weekly', 'weekdays' => range(1, 7)]);
    $this->actingAs($this->student)->get('/account')->assertInertia(fn (Assert $page) => $page
        ->has('menu', 7)->where('menu.0.date', '2026-11-25')->where('menu.6.date', '2026-12-01'));
});

test('7.3 hidden', function () {
    planMenu($this, [$off = dish('Off')]);
    $off->update(['available' => false]);
    planMenu($this, [dish('Dal')], ['meal_id' => $this->dinner->id]);
    $this->dinner->update(['active' => false]);
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('menu', 0));
    $this->actingAs($this->student)->get('/account')->assertInertia(fn (Assert $page) => $page->has('menu', 7)->has('menu.0.meals', 0));
});

test('7.4 the day changes at midnight in India', function () {
    planMenu($this, [dish('Dal')]);
    planMenu($this, [dish('Rice')], ['from' => '2026-11-26']);
    foreach (['2026-11-25 18:29:00' => 'Dal', '2026-11-25 18:31:00' => 'Rice'] as $utc => $name) {
        $this->travelTo(Carbon\Carbon::parse($utc, 'UTC'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('menu.0.items.0.name', $name));
    }
});

test('7.5 text is sent as data', function () {
    planMenu($this, [dish('<script>x</script>', ['description' => '<script>y</script>'])], ['note' => '<script>z</script>']);
    $this->get('/')->assertDontSee('<script>x', false)->assertInertia(fn (Assert $page) => $page->where('menu.0.items.0.name', '<script>x</script>'));
});

test('7.6 students without an ID or who left', function () {
    planMenu($this, [dish('Dal')]);
    foreach ([makeStudent(['canteen_id' => null]), makeStudent(['left_on' => '2026-11-01'])] as $student) {
        $this->actingAs($student)->get('/account')->assertInertia(fn (Assert $page) => $page->has('menu.0.meals', 1));
    }
});

test('7.7 exactly these fields', function () {
    $this->lunch->update(['serve_from' => '12:00', 'serve_to' => '14:00']);
    planMenu($this, [dish('Curd', ['is_extra' => '1', 'price' => 15, 'description' => 'Fresh', 'food_type_id' => optionId('Veg'), 'category_id' => optionId('Other')])], ['note' => 'Hi']);
    $meal = [
        'meal' => 'Lunch', 'price' => 70, 'serve_from' => '12:00', 'serve_to' => '14:00', 'closed' => false, 'note' => 'Hi',
        'items' => [['name' => 'Curd', 'description' => 'Fresh', 'food_type' => 'Veg', 'category' => 'Other', 'extra_price' => 15]],
    ];
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('menu', [$meal]));
    $this->actingAs($this->student)->get('/account')->assertInertia(fn (Assert $page) => $page->where('menu.0', ['date' => '2026-11-25', 'meals' => [$meal]]));
});

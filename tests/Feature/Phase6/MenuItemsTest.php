<?php

use App\Models\MealPrice;
use App\Models\MenuPlan;
use App\Models\Menus;
use App\Models\StudentAttendance;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

test('2.1 new fields', function () {
    $this->post('/menus', ['name' => 'Dal', 'food_type_id' => optionId('Veg'), 'category_id' => optionId('Dal'), 'description' => 'With ghee', 'available' => '0']);
    expect(Menus::sole())->food_type_id->toBe(optionId('Veg'))->category_id->toBe(optionId('Dal'))->description->toBe('With ghee')->available->toBeFalse();
    $this->post('/menus', ['name' => 'Roti', 'food_type_id' => '', 'category_id' => '', 'description' => '']);
    expect(Menus::latest('id')->first())->food_type_id->toBeNull()->category_id->toBeNull()->description->toBeNull()->available->toBeTrue();
});

test('2.2 wrong fields', function ($data) {
    $this->post('/menus', ['name' => 'Dal'] + $data)->assertSessionHasErrors(array_key_first($data));
    expect(Menus::count())->toBe(0);
})->with([
    [fn () => ['food_type_id' => optionId('Dal')]], [fn () => ['category_id' => optionId('Veg')]], [['food_type_id' => 999]],
    [['food_type_id' => [1]]], [['description' => str_repeat('a', 201)]], [['available' => 'maybe']],
]);

test('2.3 filters', function () {
    $veg = optionId('Veg');
    dish('Dal tadka', ['food_type_id' => $veg, 'category_id' => optionId('Dal')]);
    dish('Dal jain', ['food_type_id' => optionId('Jain'), 'category_id' => optionId('Dal'), 'available' => false]);
    dish('Roti', ['food_type_id' => $veg]);
    $count = fn (string $query, int $n) => $this->get("/menus?$query")->assertInertia(fn (Assert $page) => $page->has('items.data', $n));
    $count("type=$veg", 2);
    $count("type=$veg&search=dal", 1);
    $count('category=' . optionId('Dal'), 2);
    $count('availability=no', 1);
    $count('availability=yes', 2);
    $count('type=999&category=x&availability=perhaps', 3);
    $count('type[]=1&category[]=1&availability[]=no', 3);
});

test('2.4 an existing item', function () {
    Menus::insert(['name' => 'Old', 'slug' => 'old']);
    $old = Menus::sole();
    $this->get('/menus')->assertInertia(fn (Assert $page) => $page->where('items.data.0.food_type', null)->where('items.data.0.available', true));
    $this->put("/menus/{$old->id}", ['name' => 'Older'])->assertSessionHasNoErrors();
    expect($old->fresh()->available)->toBeTrue();
});

test('2.5 delete an item in a plan', function () {
    [$now, $past, $free] = [dish('Now'), dish('Past'), dish('Free')];
    planMenu($this, [$now]);
    MenuPlan::create(['meal_id' => $this->lunch->id, 'starts_on' => '2020-01-01'])->items()->attach($past->id);
    $this->delete("/menus/{$now->id}")->assertSessionHas('error', 'Now is on the menu. Mark it as not available instead.');
    $this->delete("/menus/{$past->id}")->assertSessionHas('error');
    $this->delete("/menus/{$free->id}")->assertSessionHas('success');
    expect(Menus::count())->toBe(2);
});

test('2.6 an item that is not available', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    planMenu($this, [$curd, dish('Dal')]);
    $this->put("/menus/{$curd->id}", ['name' => 'Curd', 'is_extra' => 'on', 'price' => 15, 'available' => '0']);

    markMeal($this, $this->lunch, ['include_extra' => 'yes', 'extra_items' => [$curd->id => 1]])->assertSessionHas('error');
    expect(StudentAttendance::count())->toBe(0);
    $this->get("/student-attendance/create/{$this->student->id}")->assertInertia(fn (Assert $page) => $page->has('extras', 0));
    $day = today()->toDateString();
    $this->get('/menu-plan')->assertInertia(fn (Assert $page) => $page->has("menus.$day.{$this->lunch->id}.items", 2)->where("menus.$day.{$this->lunch->id}.items.0.available", false));
    $this->get('/')->assertInertia(fn (Assert $page) => $page->has('menu.0.items', 1)->where('menu.0.items.0.name', 'Dal'));
});

test('2.7 a meal that is off', function () {
    $entry = markMeal($this, $this->lunch, [], true);
    payBillOf($this, 70);
    planMenu($this, [dish('Dal')], ['meal_id' => $this->dinner->id]);
    $this->put("/meal-price/{$this->lunch->id}", ['name' => 'Lunch', 'price' => 70, 'active' => false])->assertSessionHas('success');

    foreach (['/', '/dashboard', '/student-attendance', '/student-attendance/bulk', '/student-attendance/quick', "/student-attendance/create/{$this->student->id}", '/menu-plan'] as $url) {
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('meals', 1)->where('meals.0.name', 'Dinner'));
    }
    $this->get("/student-attendance?meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->where('meal', null));
    $this->get("/student-attendance/show/{$this->student->id}?from=2026-01-01&to=2026-01-31")->assertInertia(fn (Assert $page) => $page->where('rows.data.0.meal', 'Lunch'));
    $this->get('/bill/1/receipt')->assertOk();
    expect($entry)->not->toBeNull();
});

test('2.8 marking an inactive meal', function () {
    $this->lunch->update(['active' => false]);
    $message = 'Lunch is turned off in Meal prices.';
    markMeal($this, $this->lunch)->assertSessionHas('error', $message);
    bulk($this, [$this->student->id])->assertSessionHas('error', $message);
    $this->post('/student-attendance/quick', ['canteen_id' => 'C1', 'meal_id' => $this->lunch->id])->assertSessionHasErrors(['canteen_id' => "Asha Patel: $message"]);
    expect(StudentAttendance::count())->toBe(0);
});

test('2.9 serving times', function ($from, $to, $ok) {
    $response = $this->put("/meal-price/{$this->lunch->id}", ['name' => 'Lunch', 'price' => 70, 'serve_from' => $from, 'serve_to' => $to]);
    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors();
    expect($this->lunch->fresh()->serve_from)->toBe($ok ? $from : null);
})->with([['12:00', '', false], ['', '14:00', false], ['14:00', '12:00', false], ['12:00', '12:00', false], ['25:00', '26:00', false], ['12:00', '14:30', true], [null, null, true]]);

test('2.10 the last active meal stays on, and meals move', function () {
    $this->post("/meal-price/{$this->dinner->id}/move", ['direction' => 'up']);
    expect(MealPrice::active()->pluck('name')->all())->toBe(['Dinner', 'Lunch']);
    $this->put("/meal-price/{$this->lunch->id}", ['name' => 'Lunch', 'price' => 70, 'active' => false]);
    $this->put("/meal-price/{$this->dinner->id}", ['name' => 'Dinner', 'price' => 100, 'active' => false])->assertSessionHasErrors('active');
    expect(MealPrice::active()->pluck('name')->all())->toBe(['Dinner']);
});

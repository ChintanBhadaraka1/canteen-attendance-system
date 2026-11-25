<?php

use App\Models\ActivityLog;
use App\Models\MenuOption;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

test('1.1 options exist after the migration', function () {
    expect(MenuOption::of('food_type')->pluck('name')->all())->toBe(['Veg', 'Jain']);
    expect(MenuOption::of('category')->pluck('name')->all())->toBe(['Dal', 'Sabzi', 'Roti', 'Rice', 'Sweet', 'Drink', 'Other']);
    expect(MenuOption::where('active', false)->exists())->toBeFalse();
});

test('1.2 add, rename, turn off and on', function () {
    $this->post('/settings/options', ['kind' => 'food_type', 'name' => ' Non-veg '])->assertSessionHasNoErrors();
    $id = optionId('Non-veg');
    $this->put("/settings/options/$id", ['name' => 'Egg']);
    $this->put("/settings/options/$id", ['active' => false]);
    expect(MenuOption::find($id))->name->toBe('Egg')->active->toBeFalse();
    $this->put("/settings/options/$id", ['active' => true]);
    expect(MenuOption::find($id)->active)->toBeTrue();
    expect(ActivityLog::orderBy('id')->pluck('action')->all())->toBe(['option.added', 'option.changed', 'option.changed', 'option.changed']);
    $this->get('/settings')->assertInertia(fn (Assert $page) => $page->component('Settings/Index')->has('kinds.food_type', 3)->has('kinds.category', 7));
    $this->get('/changes?type=option')->assertInertia(fn (Assert $page) => $page->has('changes.data', 4));
});

test('1.3 bad names and kinds', function ($data) {
    $this->post('/settings/options', $data + ['kind' => 'food_type', 'name' => 'Egg'])->assertSessionHasErrors();
    expect(MenuOption::whereIn('kind', ['food_type', 'category'])->count())->toBe(9);
})->with([
    [['name' => '']], [['name' => 'a']], [['name' => str_repeat('a', 31)]], [['name' => 'veg']], [['name' => '  Veg  ']], [['name' => ['Egg']]], [['kind' => 'colour']], [['kind' => ['x']]],
]);

test('1.3 rename to a used name, and to itself', function () {
    $this->put('/settings/options/' . optionId('Jain'), ['name' => 'VEG'])->assertSessionHasErrors('name');
    $this->put('/settings/options/' . optionId('Jain'), ['name' => 'JAIN'])->assertSessionHasNoErrors();
});

test('1.4 the same name in the other kind', function () {
    $this->post('/settings/options', ['kind' => 'category', 'name' => 'Veg'])->assertSessionHasNoErrors();
    expect(MenuOption::where('name', 'Veg')->count())->toBe(2);
});

test('1.5 delete unused and used', function () {
    dish('Dal', ['food_type_id' => optionId('Jain')]);
    dish('Roti', ['food_type_id' => optionId('Jain')]);
    $this->delete('/settings/options/' . optionId('Jain'))->assertSessionHas('error', 'Jain is used by 2 menu items. Turn it off instead.');
    $this->delete('/settings/options/' . optionId('Other'))->assertSessionHas('success');
    expect(MenuOption::whereIn('kind', ['food_type', 'category'])->count())->toBe(8);
    $this->delete('/settings/options/999')->assertNotFound();
});

test('1.6 the only active food type stays on', function () {
    $this->put('/settings/options/' . optionId('Jain'), ['active' => false]);
    $this->put('/settings/options/' . optionId('Veg'), ['active' => false])->assertSessionHas('error');
    $this->delete('/settings/options/' . optionId('Veg'))->assertSessionHas('error');
    expect(MenuOption::where('name', 'Veg')->sole()->active)->toBeTrue();
});

test('1.7 an option that is off', function () {
    $jain = optionId('Jain');
    $item = dish('Dal', ['food_type_id' => $jain]);
    $this->put("/settings/options/$jain", ['active' => false]);

    $this->get('/menus/create')->assertInertia(fn (Assert $page) => $page->has('options.food_type', 1)->where('options.food_type.0.name', 'Veg'));
    $this->get("/menus/{$item->id}/edit")->assertInertia(fn (Assert $page) => $page->has('options.food_type', 2)->where('options.food_type.1.name', 'Jain (off)'));
    $this->put("/menus/{$item->id}", ['name' => 'Dal tadka', 'food_type_id' => $jain])->assertSessionHasNoErrors();
    $this->post('/menus', ['name' => 'Kadhi', 'food_type_id' => $jain])->assertSessionHasErrors('food_type_id');
});

test('1.8 move', function () {
    $names = fn () => MenuOption::of('category')->pluck('name')->take(3)->all();
    $this->post('/settings/options/' . optionId('Dal') . '/move', ['direction' => 'up']);
    $this->post('/settings/options/' . optionId('Other') . '/move', ['direction' => 'down']);
    expect($names())->toBe(['Dal', 'Sabzi', 'Roti']);
    $this->post('/settings/options/' . optionId('Roti') . '/move', ['direction' => 'up']);
    expect($names())->toBe(['Dal', 'Roti', 'Sabzi']);
    $this->post('/settings/options/' . optionId('Roti') . '/move', ['direction' => 'x'])->assertSessionHasErrors('direction');
});

test('1.9 guest and student', function () {
    $routes = [['get', '/settings'], ['post', '/settings/options'], ['put', '/settings/options/1'], ['delete', '/settings/options/1'], ['post', '/settings/options/1/move']];
    $this->actingAs($this->student);
    foreach ($routes as [$method, $url]) {
        $this->$method($url)->assertForbidden();
    }
    auth()->logout();
    foreach ($routes as [$method, $url]) {
        $this->$method($url)->assertRedirect('/login');
    }
});

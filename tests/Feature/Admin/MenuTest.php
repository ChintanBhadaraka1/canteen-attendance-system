<?php

use App\Models\MealPrice;
use App\Models\Menus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->actingAs(makeAdmin()));

function menuItem(string $name, array $data = []): Menus
{
    return Menus::create($data + ['name' => $name, 'slug' => str($name)->slug()->value()]);
}

test('menu list, search and paging', function () {
    menuItem('Curd', ['is_extra' => '1', 'price' => 15]);
    foreach (range(1, 10) as $n) {
        menuItem("Dish $n");
    }

    $this->get('/menus')->assertInertia(fn (Assert $page) => $page->component('Menu/Index')->has('items.data', 10));
    $this->get('/menus?page=2')->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
    $this->get('/menus?search=curd')->assertInertia(fn (Assert $page) => $page
        ->has('items.data', 1)->where('items.data.0.is_extra', true)->where('items.data.0.price', '15')->where('items.data.0.images', null));
});

test('menu form pages', function () {
    $curd = menuItem('Curd', ['is_extra' => '1', 'price' => 15]);

    $this->get('/menus/create')->assertInertia(fn (Assert $page) => $page->component('Menu/Form')->where('item', null));
    $this->get("/menus/{$curd->id}/edit")->assertInertia(fn (Assert $page) => $page->component('Menu/Form')->where('item.is_extra', true));
    $this->get("/menus/{$curd->id}")->assertMethodNotAllowed();
});

test('menu item is added, changed and deleted', function () {
    $this->post('/menus', ['name' => 'Curd', 'is_extra' => 'on', 'price' => 15])->assertRedirect('/menus')->assertSessionHas('success', 'Curd added.');
    $curd = Menus::sole();
    expect($curd->is_extra)->toEqual('1');

    $this->put("/menus/{$curd->id}", ['name' => 'Dahi'])->assertRedirect('/menus')->assertSessionHas('success', 'Changes to Dahi saved.');
    expect($curd->fresh())->name->toBe('Dahi')->is_extra->toEqual('0');

    $this->delete("/menus/{$curd->id}")->assertRedirect('/menus')->assertSessionHas('success', 'Dahi deleted.');
    expect(Menus::count())->toBe(0);
});

test('extra item needs a price', function () {
    $this->post('/menus', ['name' => 'C', 'is_extra' => 'on'])
        ->assertSessionHasErrors(['name', 'price' => 'Enter a price for this extra item.']);
});

test('menu photo is saved and replaced', function () {
    $public = storage_path('framework/testing/public-' . uniqid());
    $this->app->usePublicPath($public);

    try {
        $this->post('/menus', ['name' => 'Curd', 'images' => UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')]);
        $curd = Menus::sole();
        $old = $curd->images;
        expect(File::exists("$public/image/menus/$old"))->toBeTrue();

        $this->post("/menus/{$curd->id}", ['_method' => 'put', 'name' => 'Curd', 'images' => UploadedFile::fake()->create('b.png', 10, 'image/png')]);
        expect($curd->fresh()->images)->not->toBe($old);
        expect(File::files("$public/image/menus"))->toHaveCount(1);
    } finally {
        File::deleteDirectory($public);
    }
});

test('meal prices list and forms', function () {
    $lunch = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);

    $this->get('/meal-price')->assertInertia(fn (Assert $page) => $page
        ->component('MealPrices/Index')->where('meals', [['id' => $lunch->id, 'name' => 'Lunch', 'price' => '70']]));
    $this->get('/meal-price/create')->assertInertia(fn (Assert $page) => $page->component('MealPrices/Form')->where('meal', null));
    $this->get("/meal-price/{$lunch->id}/edit")->assertInertia(fn (Assert $page) => $page->component('MealPrices/Form')->where('meal.price', '70'));
    $this->get("/meal-price/{$lunch->id}")->assertMethodNotAllowed();
});

test('meal is added, changed and deleted', function () {
    $this->post('/meal-price', ['name' => 'Tea', 'price' => 10])->assertRedirect('/meal-price')->assertSessionHas('success', 'Tea added.');
    $tea = MealPrice::sole();

    $this->put("/meal-price/{$tea->id}", ['name' => 'Tea', 'price' => 12])->assertRedirect('/meal-price')->assertSessionHas('success', 'Changes to Tea saved.');
    expect($tea->fresh()->price)->toEqual('12');
    $this->post('/meal-price', ['name' => '', 'price' => -1])->assertSessionHasErrors(['name', 'price']);

    $this->delete("/meal-price/{$tea->id}")->assertRedirect('/meal-price')->assertSessionHas('success', 'Tea deleted.');
    expect(MealPrice::count())->toBe(0);
});

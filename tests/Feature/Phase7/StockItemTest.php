<?php

use App\Models\ActivityLog;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockPack;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->actingAs(makeAdmin()));

function addItems($test, mixed $rows)
{
    return $test->post('/stock', ['token' => token(), 'rows' => $rows]);
}

test('3.1 three items, two with opening', function () {
    addItems($this, [
        ['name' => 'Rice', 'unit' => 'kg', 'category_id' => optionId('Grain'), 'low_level' => '5', 'quantity' => '10', 'amount' => '500'],
        ['name' => '', 'unit' => 'kg', 'category_id' => '', 'low_level' => '', 'quantity' => '', 'amount' => ''],
        ['name' => 'Oil', 'unit' => 'litre', 'quantity' => '2.5', 'amount' => '0'],
        ['name' => 'Egg', 'unit' => 'piece'],
    ])->assertSessionHasNoErrors()->assertRedirect('/stock');
    expect(StockItem::count())->toBe(3)->and(StockEntry::sole()->type)->toBe('opening')->and(StockMovement::count())->toBe(2)
        ->and(stockOf(StockItem::where('name', 'Rice')->sole()))->toBe([10000, 50000])->and(StockItem::where('name', 'Rice')->sole()->low_level)->toBe(5000)
        ->and(ActivityLog::where('action', 'stock_item.added')->count())->toBe(3)->and(ActivityLog::where('action', 'stock.opening')->count())->toBe(2);
});

test('3.2 bad rows', function ($rows, $field) {
    stockItem('Rice');
    addItems($this, $rows())->assertSessionHasErrors($field);
    expect(StockItem::count())->toBe(1);
})->with([
    'same name' => [fn () => [['name' => 'Dal', 'unit' => 'kg'], ['name' => 'dAL', 'unit' => 'kg']], ['rows.1.name' => 'Row 2: dAL is in two rows.']],
    'existing' => [fn () => [['name' => 'Dal', 'unit' => 'kg'], ['name' => 'RICE', 'unit' => 'kg']], ['rows.1.name' => 'Row 2: RICE is already in the list.']],
    'unit' => [fn () => [['name' => 'Dal', 'unit' => 'box']], 'rows.0.unit'],
    'category' => [fn () => [['name' => 'Dal', 'unit' => 'kg', 'category_id' => optionId('Veg')]], 'rows.0.category_id'],
    '51' => [fn () => array_map(fn ($i) => ['name' => "Item $i", 'unit' => 'kg'], range(1, 51)), 'rows'],
    'none' => [fn () => [], 'rows'],
    'only empty' => [fn () => [['name' => '', 'unit' => 'kg']], 'rows'],
    'no list' => [fn () => 'x', 'rows'],
    'a text' => [fn () => ['x'], 'rows.0'],
    'name list' => [fn () => [['name' => ['x'], 'unit' => 'kg']], 'rows.0.name'],
    'short' => [fn () => [['name' => 'D', 'unit' => 'kg']], 'rows.0.name'],
]);

test('3.3 opening quantity and value go together', function ($row, $field) {
    addItems($this, [$row + ['name' => 'Dal', 'unit' => 'kg']])->assertSessionHasErrors($field);
    expect(StockItem::count())->toBe(0);
})->with([[['quantity' => '1'], 'rows.0.amount'], [['amount' => '1'], 'rows.0.quantity'], [['quantity' => '0', 'amount' => '1'], 'rows.0.quantity'], [['quantity' => '1', 'amount' => '-1'], 'rows.0.amount'], [['low_level' => 'x'], 'rows.0.low_level']]);

test('3.4 unit', function () {
    $rice = stockItem('Rice');
    $change = fn ($unit) => $this->put("/stock/{$rice->id}", ['name' => 'Rice', 'unit' => $unit]);
    $change('litre')->assertSessionHasNoErrors();
    expect($rice->fresh()->unit)->toBe('litre');
    buy($this, [[$rice->fresh(), '1', '1']]);
    $change('kg')->assertSessionHasErrors('unit');
    $change('litre')->assertSessionHasNoErrors();
    expect(ActivityLog::where('action', 'stock_item.changed')->count())->toBe(1);
});

test('3.5 packs', function () {
    $rice = stockItem('Rice');
    $add = fn ($name, $size) => $this->post("/stock/{$rice->id}/packs", compact('name', 'size'));
    $add('Bag', '25')->assertSessionHasNoErrors();
    $add('Bag', '50')->assertSessionHasNoErrors();
    $add('Bag', '25')->assertSessionHasErrors('name');
    $add('Tin', '0')->assertSessionHasErrors('size');
    $add('Tin', '1.0005')->assertSessionHasErrors('size');
    $add('Tin', '1000001')->assertSessionHasErrors('size');
    $add('KG', '2')->assertSessionHasErrors('name');
    expect(StockPack::pluck('size')->all())->toBe([25000, 50000]);
});

test('3.6 a pack turned off', function () {
    $rice = stockItem('Rice');
    $bag = StockPack::create(['stock_item_id' => $rice->id, 'name' => 'Bag', 'size' => 25000]);
    buy($this, [[$rice, '2', '10', "pack:{$bag->id}"]]);
    $this->patch("/stock/packs/{$bag->id}/active", ['active' => false])->assertSessionHasNoErrors();
    $this->get("/stock/{$rice->id}")->assertInertia(fn ($page) => $page->where('rows.data.0.entered', '2 Bag × 25 kg')->where('item.packs.0.active', false));
    $this->get('/purchases/create')->assertInertia(fn ($page) => $page->has('items.0.units', 2));
    buy($this, [[$rice, '2', '10', "pack:{$bag->id}"]])->assertSessionHasErrors('rows.0.unit');
    $this->patch('/stock/packs/99/active', ['active' => false])->assertNotFound();
    $this->patch("/stock/packs/{$bag->id}/active", ['active' => 'x'])->assertSessionHasErrors();
});

test('3.7 lists', function () {
    stockItem('Rice', ['category_id' => optionId('Grain'), 'low_level' => 0]);
    stockItem('Milk', ['category_id' => optionId('Dairy')]);
    stockItem('Old', ['active' => false]);
    $names = fn ($query) => collect($this->get("/stock?$query")->assertOk()->inertiaProps('items')['data'])->pluck('name')->all();
    expect($names(''))->toBe(['Milk', 'Rice'])->and($names('search=ri'))->toBe(['Rice'])->and($names('category=' . optionId('Dairy')))->toBe(['Milk'])
        ->and($names('show=low'))->toBe(['Rice'])->and($names('show=off'))->toBe(['Old'])->and($names('sort=name&dir=desc'))->toBe(['Rice', 'Milk'])
        ->and($names('category=999&show=x&sort=id;drop&dir[]=x&search[]=x&category[]=1'))->toBe(['Milk', 'Rice']);
});

test('3.8 low', function () {
    foreach (['Below' => [10, 9], 'Equal' => [10, 10], 'Above' => [10, 11], 'None' => [null, 0]] as $name => [$level, $have]) {
        StockItem::forceCreate(['name' => $name, 'unit' => 'kg', 'low_level' => $level, 'on_hand' => $have]);
    }
    StockItem::forceCreate(['name' => 'Off', 'unit' => 'kg', 'low_level' => 10, 'active' => false]);
    $this->get('/stock?show=low')->assertInertia(fn ($page) => $page->has('items.data', 2)->where('items.data.0.name', 'Below')->where('items.data.1.low', true)->where('low', 2));
    $this->get('/stock/shopping-list')->assertInertia(fn ($page) => $page->has('items', 2)->where('items.1.name', 'Equal'));
});

test('3.9 history', function () {
    $rice = stockItem('Rice');
    foreach (range(1, 6) as $i) {
        buy($this, [[$rice, '2', '10']]);
        stockOut($this, 'use', [[$rice, '1']]);
    }
    cancelStock($this, lastEntry()->id);
    $one = $this->get("/stock/{$rice->id}")->inertiaProps('rows');
    $two = $this->get("/stock/{$rice->id}?page=2")->inertiaProps('rows');
    $rows = array_reverse(array_merge($one['data'], $two['data']));
    $left = 0;
    foreach ($rows as $row) {
        $left += $row['quantity'];
        expect($row['after_quantity'])->toBe($left);
    }
    expect($rows)->toHaveCount(13)->and($left)->toBe(stockOf($rice)[0])->and($one['data'][0]['label'])->toBe('Cancelled')->and($one['data'][1]['cancelled'])->toBeTrue();
});

test('3.10 totals in the form are ignored', function () {
    $rice = stockItem('Rice');
    $this->put("/stock/{$rice->id}", ['name' => 'Rice 2', 'unit' => 'kg', 'on_hand' => 5000, 'value' => 5]);
    addItems($this, [['name' => 'Dal', 'unit' => 'kg', 'on_hand' => 5000, 'value' => 5]]);
    expect(stockOf($rice))->toBe([0, 0])->and($rice->fresh()->name)->toBe('Rice 2')->and(stockOf(StockItem::where('name', 'Dal')->sole()))->toBe([0, 0]);
});

test('3.10 edit checks', function () {
    $rice = stockItem('Rice');
    stockItem('Dal');
    $this->put("/stock/{$rice->id}", ['name' => 'dal', 'unit' => 'kg'])->assertSessionHasErrors('name');
    $this->put("/stock/{$rice->id}", ['name' => 'RICE', 'unit' => 'kg', 'low_level' => '0'])->assertSessionHasNoErrors();
    expect($rice->fresh()->low_level)->toBe(0);
    $this->put("/stock/{$rice->id}", ['name' => 'RICE', 'unit' => 'kg', 'category_id' => optionId('Veg')])->assertSessionHasErrors('category_id');
    $this->get("/stock/{$rice->id}/edit")->assertOk();
    $this->get('/stock/create')->assertOk();
    $this->get('/stock/999')->assertNotFound();
    $this->patch("/stock/{$rice->id}/active", ['active' => false])->assertSessionHas('success');
    expect($rice->fresh()->active)->toBeFalse();
});

test('3.11 query counts', function () {
    $queries = function (string $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        return count(DB::getQueryLog());
    };
    $urls = ['/stock', '/stock/use', '/stock/count', '/purchases/create', '/purchases', '/stock/shopping-list', '/dashboard'];
    $add = function ($i) {
        $item = stockItem("Item $i", ['low_level' => 0, 'category_id' => optionId('Grain')]);
        StockPack::create(['stock_item_id' => $item->id, 'name' => 'Bag', 'size' => 1000]);
        buy($this, [[$item, '1', '1']]);
        stockOut($this, 'use', [[$item, '1']]);

        return $item;
    };
    $first = $add(0);
    $before = array_map($queries, $urls);
    foreach (range(1, 19) as $i) {
        $add($i);
    }
    expect(array_map($queries, $urls))->toBe($before);
    $one = $queries("/stock/{$first->id}");
    foreach (range(1, 5) as $i) {
        buy($this, [[$first, '1', '1']]);
    }
    expect($queries("/stock/{$first->id}"))->toBe($one);
});

test('3.12 only admins', function () {
    $urls = collect(app('router')->getRoutes())->filter(fn ($route) => preg_match('#^(stock|purchases)(/|$)#', $route->uri()));
    expect($urls->count())->toBeGreaterThan(20);
    foreach ($urls as $route) {
        $url = preg_replace('/\{[^}]+\}/', '1', $route->uri());
        auth()->logout();
        $this->call($route->methods()[0], $url)->assertRedirect('/login');
        $this->actingAs(makeStudent())->call($route->methods()[0], $url)->assertForbidden();
    }
});

test('settings lists', function () {
    $grain = optionId('Grain');
    stockItem('Rice', ['category_id' => $grain]);
    $this->delete("/settings/options/$grain")->assertSessionHas('error', 'Grain is used by 1 stock item. Turn it off instead.');
    $this->post('/settings/options', ['kind' => 'shop', 'name' => 'Ramesh'])->assertSessionHasNoErrors();
    $shop = optionId('Ramesh');
    buy($this, [[StockItem::sole(), '1', '1']], ['shop_id' => $shop]);
    $this->delete("/settings/options/$shop")->assertSessionHas('error', 'Ramesh is used by 1 purchase. Turn it off instead.');
    $this->put("/settings/options/$shop", ['active' => false])->assertSessionHas('success');
    stockOut($this, 'waste', [[StockItem::sole(), '1']]);
    $this->delete('/settings/options/' . optionId('Spoiled'))->assertSessionHas('error', 'Spoiled is used by 1 waste record. Turn it off instead.');
    $this->get('/changes?type=stock')->assertInertia(fn ($page) => $page->has('changes.data', 2));
});

test('3.1 the same form twice', function () {
    $data = ['token' => token(), 'rows' => [['name' => 'Rice', 'unit' => 'kg', 'quantity' => '10', 'amount' => '500']]];
    $this->post('/stock', $data)->assertSessionHas('success');
    $this->post('/stock', $data)->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(StockItem::count())->toBe(1)->and(StockEntry::count())->toBe(1);
});

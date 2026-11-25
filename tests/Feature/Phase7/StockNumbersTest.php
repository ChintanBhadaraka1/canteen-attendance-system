<?php

use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockPack;
use App\Support\Quantity;
use App\Support\Rupees;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

afterEach(fn () => DB::setDefaultConnection('sqlite'));

test('1.1 good quantities', function ($text, $value) {
    expect(Quantity::parse($text))->toBe($value);
})->with([['0.001', 1], ['2.5', 2500], ['1000000', 1000000000], [' 3 ', 3000]]);

test('1.2 bad quantities', function ($text) {
    expect(Quantity::parse($text))->toBeNull();
    $this->actingAs(makeAdmin());
    buy($this, [[stockItem('Rice'), $text, '10']])->assertSessionHasErrors();
    expect(StockEntry::count())->toBe(0);
})->with(['1.0005', '-1', '1e3', '1,5', '.5', '5.', '', 'abc', '١', [['2']]]);

test('1.2 a line break is not part of a number', fn () => expect(Quantity::parse("1\n2"))->toBeNull()->and(Rupees::parse("1.5.2"))->toBeNull());

test('1.3 money', function ($text, $value) {
    expect(Rupees::parse($text))->toBe($value);
})->with([['0.1', 10], ['42.50', 4250], ['99.999', null], ['99999999.99', 9999999999], ['100000000', null]]);

test('1.4 show and parse give the same value back', function () {
    mt_srand(7);
    foreach (range(1, 1000) as $i) {
        $n = mt_rand(0, 9999999999);
        expect(Quantity::parse(Quantity::show($n)))->toBe($n)->and(Rupees::parse(Rupees::show($n)))->toBe($n);
    }
    expect(Quantity::show(2500))->toBe('2.5')->and(Quantity::show(100000))->toBe('100')->and(Quantity::show(0))->toBe('0')->and(Quantity::show(-1))->toBe('-0.001')
        ->and(Rupees::show(4250))->toBe('42.50')->and(Rupees::format(125000))->toBe('₹1,250.00')->and(Rupees::format(1000000000))->toBe('₹1,00,00,000.00')->and(Rupees::format(-5))->toBe('−₹0.05');
});

test('1.5 units', function () {
    $this->actingAs(makeAdmin());
    $rice = stockItem('Rice');
    $bag = StockPack::create(['stock_item_id' => $rice->id, 'name' => 'Bag', 'size' => 25000]);
    $egg = stockItem('Egg', ['unit' => 'piece']);
    expect($rice->fresh()->sizes())->toBe(['kg' => 1000, 'g' => 1, "pack:{$bag->id}" => 25000]);
    buy($this, [[$rice, '1.5', '10', 'g']])->assertSessionHasErrors(['rows.0.quantity' => 'Row 1: Too many decimals.']);
    buy($this, [[$rice, '500', '10', 'g']]);
    buy($this, [[$rice, '0.5', '10', "pack:{$bag->id}"]]);
    buy($this, [[$egg, '1.5', '10']]);
    expect(stockOf($rice)[0])->toBe(13000)->and(stockOf($egg)[0])->toBe(1500);
    $cup = StockPack::create(['stock_item_id' => $rice->id, 'name' => 'Cup', 'size' => 125]);
    buy($this, [[$rice, '0.001', '10', "pack:{$cup->id}"]])->assertSessionHasErrors(['rows.0.quantity' => 'Row 1: Too many decimals.']);
});

test('1.6 the database refuses duplicates', function () {
    $entry = fn ($data) => StockEntry::create($data + ['type' => 'use', 'date' => '2026-01-01', 'token' => token(), 'user_name' => 'x']);
    $first = $entry(['token' => 'a']);
    expect(fn () => $entry(['token' => 'a']))->toThrow(UniqueConstraintViolationException::class);
    $entry(['type' => 'cancel', 'cancels_id' => $first->id]);
    expect(fn () => $entry(['type' => 'cancel', 'cancels_id' => $first->id]))->toThrow(UniqueConstraintViolationException::class);
    stockItem('Rice');
    expect(fn () => StockItem::create(['name' => 'Rice', 'unit' => 'kg']))->toThrow(UniqueConstraintViolationException::class);
});

test('1.7 migrate, roll back, migrate', function () {
    separateDatabase();
    $options = fn () => DB::table('menu_options')->whereIn('kind', ['food_type', 'category'])->orderBy('id')->get()->toJson();
    $before = $options();
    $steps = count(glob(database_path('migrations/2026_11_28_000002*'))) + count(glob(database_path('migrations/2026_11_29*')));
    Artisan::call('migrate:rollback', ['--step' => $steps]);
    expect(DB::getSchemaBuilder()->hasTable('stock_items'))->toBeFalse()->and(DB::table('menu_options')->count())->toBe(9);
    Artisan::call('migrate');
    expect($options())->toBe($before)->and(DB::table('menu_options')->where('kind', 'stock_category')->count())->toBe(7);
});

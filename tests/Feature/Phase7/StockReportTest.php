<?php

use App\Models\StockEntry;
use App\Models\StockItem;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2027-01-05 10:00');
    $this->actingAs(makeAdmin());
    $this->rice = stockItem('Rice');
    $this->report = fn (string $query) => $this->get("/purchases/report?$query")->assertOk()->inertiaProps();
});

test('7.1 Sunday and Monday', function () {
    buy($this, [[$this->rice, '1', '10']], ['date' => '2026-11-29']);
    buy($this, [[$this->rice, '1', '20']], ['date' => '2026-11-30']);
    $weeks = ($this->report)('from=2026-11-01&to=2026-12-31')['periods'];
    expect(array_column($weeks, 'label'))->toBe(['23 Nov 2026 to 29 Nov 2026', '30 Nov 2026 to 6 Dec 2026'])->and(array_column($weeks, 'total'))->toBe([1000, 2000]);
    $months = ($this->report)('from=2026-11-01&to=2026-12-31&by=month')['periods'];
    expect($months)->toHaveCount(1)->and($months[0]['label'])->toBe('November 2026')->and($months[0]['total'])->toBe(3000);
});

test('7.2 new year', function () {
    buy($this, [[$this->rice, '1', '10']], ['date' => '2026-12-31']);
    buy($this, [[$this->rice, '1', '20']], ['date' => '2027-01-01']);
    expect(($this->report)('from=2026-12-01&to=2027-01-31')['periods'])->toHaveCount(1)
        ->and(array_column(($this->report)('from=2026-12-01&to=2027-01-31&by=month')['periods'], 'label'))->toBe(['December 2026', 'January 2027'])
        ->and(($this->report)('from=2026-12-31&to=2027-01-01')['periods'][0]['label'])->toBe('31 Dec 2026 to 1 Jan 2027')
        ->and(($this->report)('from=2026-12-31&to=2027-01-31&by=month')['periods'][0]['label'])->toBe('December 2026 (31 Dec 2026)');
});

test('7.3 discount and charges', function () {
    buy($this, [[$this->rice, '1', '100']], ['discount' => '10', 'charges' => '50']);
    $report = ($this->report)('');
    expect($report['periods'][0]['total'])->toBe(14000)->and($report['items'][0]['bought_cost'])->toBe(10000);
});

test('7.4 cancelled', function () {
    buy($this, [[$this->rice, '1', '100']]);
    cancelStock($this, lastEntry()->id);
    expect(($this->report)(''))->toMatchArray(['periods' => [], 'items' => [], 'shops' => [], 'total' => 0]);
});

test('7.5 bought, used, wasted', function () {
    buy($this, [[$this->rice, '10', '100']]);
    stockOut($this, 'use', [[$this->rice, '2']]);
    stockOut($this, 'use', [[$this->rice, '1']]);
    cancelStock($this, lastEntry()->id);
    stockOut($this, 'waste', [[$this->rice, '3']]);
    expect(($this->report)('')['items'][0])->toMatchArray(['bought' => 10000, 'bought_cost' => 10000, 'used' => 2000, 'used_cost' => 2000, 'wasted' => 3000, 'wasted_cost' => 3000]);
});

test('7.6 bad dates', function ($query, $message) {
    $report = ($this->report)($query);
    expect($report['filters'])->toBe(['from' => '2027-01-01', 'to' => '2027-01-31', 'by' => 'week'])->and($report['message'] !== null)->toBe($message)->and($report['items'])->toBe([]);
})->with([['', false], ['from=2027-01-05&to=2027-01-01', true], ['from=x&to=y', true], ['from[]=x&to=2027-01-01&by[]=month', true], ['from=2025-11-01&to=2026-12-06', true], ['from=2026-01-01', true]]);

test('7.6 366 days', function () {
    expect(($this->report)('from=2026-01-01&to=2027-01-01')['message'])->toBeNull()->and(($this->report)('from=2026-01-01&to=2027-01-02')['message'])->not->toBeNull();
});

test('7.7 everything adds up', function () {
    mt_srand(3);
    $items = [$this->rice, stockItem('Oil'), stockItem('Dal')];
    $shops = [null, ...array_map(fn ($name) => App\Models\MenuOption::create(['kind' => 'shop', 'name' => $name])->id, ['Shop a', 'Shop b'])];
    foreach (range(1, 30) as $i) {
        buy($this, array_map(fn ($item) => [$item, (string) mt_rand(1, 9), mt_rand(5, 99) . '.' . mt_rand(10, 99)], $items), [
            'shop_id' => $shops[mt_rand(0, 2)], 'discount' => mt_rand(0, 4) . '.05', 'charges' => (string) mt_rand(0, 9), 'date' => today()->subDays(mt_rand(0, 80))->toDateString(),
        ])->assertSessionHasNoErrors();
    }
    foreach (['week', 'month'] as $by) {
        $report = ($this->report)("from=2026-10-01&to=2027-01-05&by=$by");
        expect($report['total'])->toBe((int) StockEntry::sum('total'))->and(array_sum(array_column($report['periods'], 'total')))->toBe($report['total'])
            ->and(array_sum(array_column($report['shops'], 'total')))->toBe($report['total'])->and($report['shops'])->toHaveCount(3)
            ->and(array_sum(array_column($report['items'], 'bought_cost')) - $report['discount'] + $report['charges'])->toBe($report['total']);
    }
});

test('7.8 csv', function () {
    $item = stockItem('=x');
    $shop = App\Models\MenuOption::create(['kind' => 'shop', 'name' => '@y'])->id;
    buy($this, [[$item, '1.5', '1250.50']], ['shop_id' => $shop]);
    $csv = $this->get('/purchases/report/download')->assertOk()->streamedContent();
    expect($csv)->toContain("Item,'=x,kg,1.5,1250.50,0,0.00,0,0.00")->toContain("Shop,'@y,,,1250.50")->toContain('Period,"4 Jan 2027 to 10 Jan 2027",,,1250.50')->not->toContain('₹');
});

test('7.9 home card', function ($count) {
    foreach (range(1, $count ?: 1) as $i) {
        stockItem("Item $i", ['low_level' => $count ? 0 : null]);
    }
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->has('low.items', min(5, $count))->where('low.total', $count));
})->with([0, 3, 7]);

test('7.10 check-stock', function ($break, $text) {
    buy($this, [[$this->rice, '10', '100']]);
    stockOut($this, 'use', [[$this->rice, '1']]);
    cancelStock($this, lastEntry()->id);
    $this->artisan('canteen:check-stock')->assertExitCode(0);
    $break();
    $dump = fn () => DB::table('stock_items')->get() . DB::table('stock_movements')->get() . DB::table('stock_entries')->get();
    $before = $dump();
    $this->artisan('canteen:check-stock')->expectsOutputToContain($text)->assertExitCode(1);
    expect($dump())->toBe($before);
})->with([
    [fn () => DB::table('stock_items')->update(['on_hand' => 1]), 'item 1: on hand 1, movements 10000'],
    [fn () => DB::table('stock_items')->update(['value' => 1]), 'item 1: value 1, movements 10000'],
    [fn () => DB::table('stock_items')->update(['value' => -1]), 'item 1: below zero'],
    [fn () => DB::table('stock_items')->update(['on_hand' => 0]), 'item 1: value without stock'],
    [fn () => DB::table('stock_movements')->where('id', 2)->update(['after_quantity' => 1]), 'movement 2: balance 1, expected 9000'],
    [fn () => DB::table('stock_entries')->where('id', 1)->update(['total' => 1]), 'entry 1: total 1, expected 10000'],
    [fn () => DB::table('stock_movements')->where('id', 3)->update(['quantity' => 5]), 'entry 3: quantities differ from entry 2'],
    [fn () => DB::table('stock_movements')->where('id', 3)->delete(), 'entry 3: no movements'],
]);

test('7.11 query count', function () {
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->report)('from=2026-06-01&to=2027-01-31');

        return count(DB::getQueryLog());
    };
    $add = fn ($n) => collect(range(1, $n))->each(fn ($i) => buy($this, [[$this->rice, '1', '1']], ['date' => today()->subDays($i % 100)->toDateString()]));
    $add(10);
    $before = $queries();
    $add(190);
    expect($queries())->toBe($before)->and(StockEntry::count())->toBe(200);
});

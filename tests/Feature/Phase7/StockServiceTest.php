<?php

use App\Models\ActivityLog;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\StockService;

beforeEach(function () {
    $this->actingAs($this->admin = makeAdmin());
    $this->rice = stockItem('Rice');
});

test('2.1 average cost', function () {
    buy($this, [[$this->rice, '10', '100']]);
    buy($this, [[$this->rice, '10', '200']]);
    stockOut($this, 'use', [[$this->rice, '5']])->assertSessionHasNoErrors();
    expect(stockOf($this->rice))->toBe([15000, 22500])->and(StockMovement::latest('id')->first()->cost)->toBe(-7500);
});

test('2.2 rounding', function () {
    buy($this, [[$this->rice, '3', '100']]);
    foreach ([3333, 3334, 3333] as $cost) {
        stockOut($this, 'use', [[$this->rice, '1']]);
        expect(StockMovement::latest('id')->first()->cost)->toBe(-$cost);
    }
    expect(stockOf($this->rice))->toBe([0, 0]);
});

test('2.3 0.1 and 0.2', function () {
    buy($this, [[$this->rice, '0.1', '0.1'], [$this->rice, '0.2', '0.2']]);
    stockOut($this, 'use', [[$this->rice, '0.3']])->assertSessionHasNoErrors();
    expect(stockOf($this->rice))->toBe([0, 0]);
});

test('2.4 more than the stock', function () {
    buy($this, [[$this->rice, '3', '100']]);
    stockOut($this, 'use', [[$this->rice, '3.001']])->assertSessionHasErrors(['rows.0.quantity' => 'Row 1: Only 3 kg of Rice left. Do a stock count if this is wrong.']);
    expect(stockOf($this->rice))->toBe([3000, 10000])->and(StockEntry::count())->toBe(1);
});

test('2.5 free', function () {
    buy($this, [[$this->rice, '3', '0']])->assertSessionHasNoErrors();
    stockOut($this, 'use', [[$this->rice, '1']]);
    expect(stockOf($this->rice))->toBe([2000, 0]);
    $this->get("/stock/{$this->rice->id}")->assertInertia(fn ($page) => $page->where('item.average', 0));
});

test('2.6 limits', function () {
    buy($this, [[$this->rice, '1000000', '10000000']])->assertSessionHasNoErrors();
    buy($this, [[$this->rice, '0.001', '0']])->assertSessionHasErrors('rows.0.quantity');
    stockOut($this, 'use', [[$this->rice, '333333.333']])->assertSessionHasNoErrors();
    expect(stockOf($this->rice))->toBe([666666667, 666666667]);
    $oil = stockItem('Oil');
    buy($this, [[$oil, '1', '10000000.01']])->assertSessionHasErrors('rows.0.quantity');
    buy($this, [[$oil, '9999999.999', '1']])->assertSessionHasErrors('rows.0.quantity');
    expect(stockOf($oil))->toBe([0, 0]);
});

test('2.7 all or nothing', function () {
    $oil = stockItem('Oil');
    buy($this, [[$this->rice, '5', '50'], [$oil, '5', '50']]);
    stockOut($this, 'use', [[$this->rice, '1'], [$oil, '6']])->assertSessionHasErrors('rows.1.quantity');
    expect(StockMovement::count())->toBe(2)->and(stockOf($this->rice))->toBe([5000, 5000]);
    ActivityLog::creating(fn () => throw new RuntimeException('log'));
    expect(fn () => $this->withoutExceptionHandling()->post('/stock/use', ['date' => today()->toDateString(), 'token' => token(), 'rows' => stockRows([[$this->rice, '1']])]))->toThrow(RuntimeException::class);
    expect(StockEntry::count())->toBe(1)->and(stockOf($this->rice))->toBe([5000, 5000]);
});

test('2.8 token', function () {
    $data = ['token' => token()];
    buy($this, [[$this->rice, '5', '50']], $data)->assertSessionHas('success');
    buy($this, [[$this->rice, '5', '50']], $data)->assertRedirect('/purchases/' . StockEntry::sole()->id)->assertSessionHas('success');
    expect(stockOf($this->rice)[0])->toBe(5000);
    stockOut($this, 'use', [[$this->rice, '1']], $data)->assertSessionHasErrors('stock');
    $this->actingAs(makeAdmin());
    buy($this, [[$this->rice, '5', '50']], $data)->assertSessionHasErrors('stock');
    expect(StockEntry::count())->toBe(1);
});

test('2.9 random walk', function () {
    mt_srand(9);
    $stock = app(StockService::class);
    $items = [$this->rice, stockItem('Oil', ['unit' => 'litre']), stockItem('Egg', ['unit' => 'piece'])];
    $qty = fn () => mt_rand(0, 1) ? (string) mt_rand(1, 9) : mt_rand(0, 9) . '.' . mt_rand(1, 999);
    $done = array_fill_keys(['purchase', 'use', 'waste', 'count', 'cancel'], 0);
    foreach (range(1, 500) as $step) {
        $item = $items[mt_rand(0, 2)]->fresh();
        $other = $items[mt_rand(0, 2)];
        $type = ['purchase', 'purchase', 'use', 'use', 'waste', 'count', 'cancel'][mt_rand(0, 6)];
        $base = ['type' => $type, 'date' => today()->toDateString(), 'token' => token(), 'note' => null];
        $row = fn ($i) => ['item' => $i->id, 'quantity' => $qty(), 'unit' => $i->unit, 'amount' => mt_rand(0, 500) . '.' . mt_rand(10, 99)];
        try {
            match ($type) {
                'purchase' => $stock->save($base + ['shop_id' => null, 'bill_number' => null, 'different_bill' => false, 'method' => 'cash', 'discount' => 0, 'charges' => 0, 'paper_total' => null, 'bill_file' => null], [$row($item), $row($other)]),
                'use' => $stock->save($base, [$row($item), $row($other)]),
                'waste' => $stock->save($base + ['reason_id' => optionId('Spoiled'), 'reason_text' => null], [$row($item)]),
                'count' => $stock->count(['date' => $base['date'], 'token' => $base['token'], 'note' => null, 'reason_text' => 'Count'], [$row($item) + ['expected' => $item->on_hand]]),
                'cancel' => $stock->cancel(StockEntry::inRandomOrder(mt_rand())->value('id') ?? 0, 'Wrong'),
            };
            $done[$type]++;
        } catch (App\Exceptions\StockException|Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        foreach (StockItem::all() as $i) {
            expect($i->on_hand)->toBe((int) $i->movements()->sum('quantity'))->and($i->value)->toBe((int) $i->movements()->sum('cost'))
                ->and(min($i->on_hand, $i->value))->toBeGreaterThanOrEqual(0)->and($i->on_hand > 0 || $i->value === 0)->toBeTrue();
        }
    }
    expect(min($done))->toBeGreaterThan(10);
    $this->artisan('canteen:check-stock')->assertExitCode(0);
});

test('no decimals in stock code', function () {
    foreach (['Services/StockService', 'Support/Quantity', 'Support/Rupees', 'Support/Digits', 'Models/StockItem'] as $file) {
        expect(file_get_contents(app_path("$file.php")))->not->toMatch('/float|round\(|\(double\)|number_format/');
    }
});

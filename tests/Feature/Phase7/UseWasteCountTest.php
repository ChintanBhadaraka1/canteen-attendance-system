<?php

use App\Models\StockEntry;
use App\Models\StockMovement;

beforeEach(function () {
    $this->actingAs(makeAdmin());
    $this->rice = stockItem('Rice');
    buy($this, [[$this->rice, '10', '500']]);
});

test('5.1 use', function () {
    stockOut($this, 'use', [[$this->rice, '2.5']])->assertSessionHasNoErrors()->assertRedirect('/stock');
    expect(stockOf($this->rice))->toBe([7500, 37500]);
    stockOut($this, 'use', [[$this->rice, '500', null, 'g']]);
    expect(stockOf($this->rice))->toBe([7000, 35000])->and(StockMovement::pluck('cost')->all())->toBe([50000, -12500, -2500]);
});

test('5.2 waste', function () {
    stockOut($this, 'waste', [[$this->rice, '1']])->assertSessionHasNoErrors();
    stockOut($this, 'waste', [[$this->rice, '1']], ['reason_id' => ''])->assertSessionHasErrors('reason_id');
    $other = App\Models\MenuOption::where('kind', 'waste_reason')->where('name', 'Other')->value('id');
    stockOut($this, 'waste', [[$this->rice, '1']], ['reason_id' => $other])->assertSessionHasErrors('reason_text');
    stockOut($this, 'waste', [[$this->rice, '1']], ['reason_id' => $other, 'reason_text' => 'Rats'])->assertSessionHasNoErrors();
    stockOut($this, 'waste', [[$this->rice, '1']], ['reason_id' => optionId('Grain')])->assertSessionHasErrors('reason_id');
    expect(stockOf($this->rice)[0])->toBe(8000);
    $this->get("/stock/{$this->rice->id}")->assertInertia(fn ($page) => $page->where('rows.data.0.reason', 'Other: Rats')->where('rows.data.0.label', 'Wasted'));
});

test('5.3 two rows over the stock', function () {
    stockOut($this, 'use', [[$this->rice, '6'], [$this->rice, '6']])->assertSessionHasErrors(['rows.1.quantity' => 'Row 2: Only 4 kg of Rice left. Do a stock count if this is wrong.']);
    expect(StockEntry::count())->toBe(1)->and(stockOf($this->rice))->toBe([10000, 50000]);
});

test('5.4 use all', function () {
    stockOut($this, 'use', [[$this->rice, '3.333']]);
    stockOut($this, 'use', [[$this->rice, '6.667']]);
    expect(stockOf($this->rice))->toBe([0, 0]);
});

test('5.5 counts', function ($count, $stock, $quantity, $cost) {
    countStock($this, [[$this->rice, $count]])->assertSessionHasNoErrors();
    $row = StockMovement::latest('id')->first();
    expect(stockOf($this->rice))->toBe($stock)->and([$row->quantity, $row->cost])->toBe([$quantity, $cost]);
})->with([['9', [9000, 45000], -1000, -5000], ['11', [11000, 55000], 1000, 5000], ['0', [0, 0], -10000, -50000]]);

test('5.5 equal', function () {
    countStock($this, [[$this->rice, '10']], ['reason_text' => ''])->assertSessionHas('success', 'Everything matches. Nothing to change.');
    expect(StockEntry::count())->toBe(1);
    countStock($this, [[$this->rice, '9']], ['reason_text' => ''])->assertSessionHasErrors('reason_text');
    expect(StockEntry::count())->toBe(1);
});

test('5.6 count from nothing', function () {
    buy($this, [[$this->rice, '2', '300']]);
    stockOut($this, 'use', [[$this->rice, '12']]);
    countStock($this, [[$this->rice, '5']]);
    expect(stockOf($this->rice))->toBe([5000, 75000]);
    $salt = stockItem('Salt');
    countStock($this, [[$salt, '5']]);
    expect(stockOf($salt))->toBe([5000, 0]);
});

test('5.6 a cancelled purchase does not give the rate', function () {
    buy($this, [[$this->rice, '2', '300']]);
    cancelStock($this, lastEntry()->id);
    stockOut($this, 'use', [[$this->rice, '10']]);
    countStock($this, [[$this->rice, '1']]);
    expect(stockOf($this->rice))->toBe([1000, 5000]);
});

test('5.7 empty boxes', function () {
    $oil = stockItem('Oil');
    buy($this, [[$oil, '3', '30']]);
    countStock($this, [[$this->rice, ''], [$oil, '0']])->assertSessionHasNoErrors();
    expect(stockOf($this->rice)[0])->toBe(10000)->and(stockOf($oil)[0])->toBe(0);
    countStock($this, [[$this->rice, '']])->assertSessionHasErrors(['rows' => 'Count at least one item.']);
});

test('5.8 stock changed meanwhile', function () {
    $oil = stockItem('Oil');
    buy($this, [[$oil, '3', '30']]);
    stockOut($this, 'use', [[$this->rice, '2']]);
    countStock($this, [[$oil, '2'], [$this->rice, '9', 10000]])->assertSessionHasErrors(['stock' => 'The stock has changed: Rice now has 8 kg. Please check and save again.']);
    expect(stockOf($oil)[0])->toBe(3000)->and(stockOf($this->rice)[0])->toBe(8000);
});

test('5.9 the same item twice in a count', function () {
    countStock($this, [[$this->rice, '9'], [$this->rice, '8']])->assertSessionHasErrors('rows.0.item');
    expect(stockOf($this->rice)[0])->toBe(10000);
});

test('5.10 dates', function ($days, $ok) {
    foreach (['use', 'waste'] as $type) {
        $response = stockOut($this, $type, [[$this->rice, '1']], ['date' => today()->subDays($days)->toDateString()]);
        $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('date');
    }
    $response = countStock($this, [[$this->rice, '5']], ['date' => today()->subDays($days)->toDateString()]);
    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('date');
    expect(StockEntry::count())->toBe($ok ? 4 : 1);
})->with([[1, true], [-1, false], [366, true], [367, false]]);

test('5.11 turned off with stock', function () {
    $this->rice->update(['active' => false]);
    $this->get('/stock/use')->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.active', false));
    stockOut($this, 'use', [[$this->rice, '1']])->assertSessionHasNoErrors();
    countStock($this, [[$this->rice, '5']])->assertSessionHasNoErrors();
    buy($this, [[$this->rice, '1', '1']])->assertSessionHasErrors(['rows.0.item' => 'Row 1: Rice is turned off, so it cannot be bought.']);
    expect(stockOf($this->rice)[0])->toBe(5000);
});

test('bad input', function ($type, $data) {
    $this->post("/stock/$type", $data + ['date' => today()->toDateString(), 'token' => token(), 'rows' => stockRows([[$this->rice, '1']])])->assertSessionHasErrors();
    expect(StockEntry::count())->toBe(1);
})->with([
    ['use', ['rows' => 'x']], ['use', ['rows' => [['item' => '']]]], ['use', ['token' => ['x']]], ['use', ['note' => str_repeat('a', 201)]], ['waste', ['reason_id' => ['x']]],
    ['count', []], ['count', ['rows' => 'x']], ['count', ['rows' => ['x']]], ['count', ['rows' => [['item' => 1, 'quantity' => '1', 'unit' => 'kg', 'expected' => 'x']]]],
    ['use', ['rows' => [['item' => 99, 'quantity' => '1', 'unit' => 'kg']]]],
]);

test('count page by category', function () {
    $milk = stockItem('Milk', ['category_id' => optionId('Dairy')]);
    $this->get('/stock/count?category=' . optionId('Dairy'))->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.id', $milk->id));
    $this->get('/stock/count?category[]=x')->assertInertia(fn ($page) => $page->has('items', 2));
    $this->get('/stock/waste')->assertInertia(fn ($page) => $page->component('Stock/Entry')->has('reasons', 4));
});

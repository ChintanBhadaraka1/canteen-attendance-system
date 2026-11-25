<?php

use App\Models\ActivityLog;
use App\Models\StockEntry;
use App\Models\StockMovement;

beforeEach(function () {
    $this->actingAs(makeAdmin());
    $this->rice = stockItem('Rice');
});

test('6.1 wrong price cancelled at once', function () {
    buy($this, [[$this->rice, '10', '100']]);
    buy($this, [[$this->rice, '10', '10000']]);
    $id = lastEntry()->id;
    cancelStock($this, $id)->assertSessionHasNoErrors()->assertRedirect("/purchases/$id");
    expect(stockOf($this->rice))->toBe([10000, 10000])->and(lastEntry()->only('type', 'cancels_id', 'reason_text'))->toBe(['type' => 'cancel', 'cancels_id' => $id, 'reason_text' => 'Wrong entry'])
        ->and(ActivityLog::where('action', 'stock.cancelled')->count())->toBe(1);
});

test('6.2 already used', function () {
    buy($this, [[$this->rice, '10', '100']]);
    $id = lastEntry()->id;
    stockOut($this, 'use', [[$this->rice, '4']]);
    cancelStock($this, $id)->assertSessionHasErrors(['reason' => 'Only 6 kg of Rice left, so this cannot be cancelled.']);
    expect(stockOf($this->rice))->toBe([6000, 6000])->and(StockEntry::count())->toBe(2);
});

test('6.3 value never outlives the stock', function () {
    buy($this, [[$this->rice, '10', '100']]);
    buy($this, [[$this->rice, '10', '200']]);
    $id = lastEntry()->id;
    stockOut($this, 'use', [[$this->rice, '10']]);
    expect(StockMovement::latest('id')->first()->cost)->toBe(-15000);
    $this->get("/purchases/$id")->assertInertia(fn ($page) => $page->where('used', ['10 kg of Rice']));
    cancelStock($this, $id)->assertSessionHasNoErrors();
    expect(stockOf($this->rice))->toBe([0, 0]);
});

test('6.4 a use after more purchases', function () {
    buy($this, [[$this->rice, '10', '100']]);
    stockOut($this, 'use', [[$this->rice, '4']]);
    $id = lastEntry()->id;
    buy($this, [[$this->rice, '10', '900']]);
    cancelStock($this, $id)->assertRedirect('/stock');
    expect(stockOf($this->rice))->toBe([20000, 100000]);
});

test('6.5 one of three not cancellable', function () {
    $oil = stockItem('Oil');
    $dal = stockItem('Dal');
    buy($this, [[$this->rice, '1', '1'], [$oil, '1', '1'], [$dal, '1', '1']]);
    $id = lastEntry()->id;
    stockOut($this, 'use', [[$oil, '1']]);
    cancelStock($this, $id)->assertSessionHasErrors(['reason' => 'Only 0 kg of Oil left, so this cannot be cancelled.']);
    expect(stockOf($dal))->toBe([1000, 100])->and(StockMovement::count())->toBe(4);
});

test('6.6 what cannot be cancelled', function () {
    buy($this, [[$this->rice, '10', '100']]);
    $id = lastEntry()->id;
    countStock($this, [[$this->rice, '11']]);
    cancelStock($this, lastEntry()->id)->assertSessionHasErrors(['reason' => 'A stock count cannot be cancelled. Do a new count.']);
    cancelStock($this, $id);
    cancelStock($this, lastEntry()->id)->assertSessionHasErrors(['reason' => 'A cancellation cannot be cancelled.']);
    cancelStock($this, $id)->assertSessionHasErrors(['reason' => 'This was already cancelled.']);
    cancelStock($this, 999)->assertNotFound();
});

test('6.7 the same item twice', function () {
    buy($this, [[$this->rice, '10', '100']]);
    buy($this, [[$this->rice, '1', '7'], [$this->rice, '2', '9']]);
    cancelStock($this, lastEntry()->id)->assertSessionHasNoErrors();
    expect(stockOf($this->rice))->toBe([10000, 10000])->and(StockMovement::count())->toBe(5);
    $this->artisan('canteen:check-stock')->assertExitCode(0);
});

test('6.8 reasons', function ($reason) {
    buy($this, [[$this->rice, '10', '100']]);
    cancelStock($this, lastEntry()->id, $reason)->assertSessionHasErrors('reason');
    expect(StockEntry::count())->toBe(1);
})->with(['', ' ab ', str_repeat('a', 201), [['abc']]]);

test('6.9 all or nothing', function () {
    buy($this, [[$this->rice, '10', '100']]);
    ActivityLog::creating(fn () => throw new RuntimeException('log'));
    expect(fn () => $this->withoutExceptionHandling()->post('/stock/entries/' . lastEntry()->id . '/cancel', ['reason' => 'Wrong']))->toThrow(RuntimeException::class);
    expect(stockOf($this->rice))->toBe([10000, 10000])->and(StockEntry::count())->toBe(1);
});

test('6.10 only admins', function () {
    buy($this, [[$this->rice, '10', '100']]);
    $this->actingAs(makeStudent());
    cancelStock($this, 1)->assertForbidden();
    auth()->logout();
    cancelStock($this, 1)->assertRedirect('/login');
});

test('6.11 limits', function () {
    buy($this, [[$this->rice, '1000000', '100']]);
    stockOut($this, 'use', [[$this->rice, '10']]);
    $id = lastEntry()->id;
    buy($this, [[$this->rice, '5', '1']])->assertSessionHasNoErrors();
    cancelStock($this, $id)->assertSessionHasErrors('reason');
    expect(stockOf($this->rice)[0])->toBe(999995000);
});

test('cancel from the item page goes back there, opening too', function () {
    $this->post('/stock', ['token' => token(), 'rows' => [['name' => 'Dal', 'unit' => 'kg', 'quantity' => '1', 'amount' => '5']]]);
    $entry = lastEntry();
    $item = $entry->movements->first()->stock_item_id;
    $this->post("/stock/entries/{$entry->id}/cancel", ['reason' => 'Wrong', 'item' => $item])->assertRedirect("/stock/$item");
    expect(lastEntry()->date)->toBe($entry->date);
});

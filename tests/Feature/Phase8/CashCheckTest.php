<?php

use App\Models\CashCheck;
use App\Models\Expense;
use App\Models\PaymentHistory;
use App\Models\StaffEntry;
use App\Services\CashService;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00');
    moneySetup($this);
    module($this, 'staff');
    module($this, 'cash');
    $this->rice = stockItem('Rice');
    $this->expected = fn (string $date = '2026-11-25') => app(CashService::class)->expected($date);
});

function oneOfEach($test): void
{
    $pay = function (int $amount, string $type) use ($test) {
        markMeal($test, $test->lunch, ['date' => today()->toDateString()]);
        payBillOf($test, $amount, ['type' => $type])->assertSessionHasNoErrors();
        $test->student = makeStudent();
    };
    $pay(500, 'cash');
    $pay(300, 'upi');
    buy($test, [[$test->rice, '1', '200']]);
    buy($test, [[$test->rice, '1', '70']], ['method' => 'upi']);
    addExpense($test);
    addExpense($test, ['method' => 'bank', 'amount' => '900']);
    $test->staff = hire($test);
    staffEntry($test, $test->staff, 'payment', '2026-11', '50');
    staffEntry($test, $test->staff, 'payment', '2026-11', '60', ['method' => 'bank']);
    staffEntry($test, $test->staff, 'bonus', '2026-11', '60');
}

test('4.1 every line', function () {
    oneOfEach($this);
    expect(($this->expected)())->toMatchArray(['opening' => null, 'from' => '2026-11-25', 'change' => 15000])
        ->and(($this->expected)()['lines'])->toBe(['students' => 50000, 'walk_ins' => 0, 'purchases' => -20000, 'expenses' => -10000, 'salaries' => -5000]);
    cashCheck($this)->assertSessionHasNoErrors();
    expect(CashCheck::sole()->expected)->toBe(115000);
    walkIn($this, $this->lunch, ['date' => '2026-11-25', 'plates' => 2])->assertSessionHasNoErrors();
    walkIn($this, $this->lunch, ['date' => '2026-11-25', 'method' => 'upi']);
    expect(app(CashService::class)->changed(CashCheck::all())[CashCheck::sole()->id])->toBe(129000);
});

test('4.1 walk-ins', function () {
    walkIn($this, $this->lunch, ['date' => '2026-11-25', 'plates' => 2]);
    walkIn($this, $this->lunch, ['date' => '2026-11-25', 'method' => 'upi']);
    walkIn($this, $this->lunch, ['date' => '2026-11-24']);
    cardGuest($this, $this->lunch, ['date' => '2026-11-25']);
    expect(($this->expected)()['lines']['walk_ins'])->toBe(14000);
});

test('4.2 put in and taken out', function () {
    oneOfEach($this);
    cashCheck($this, ['put_in' => '100', 'taken_out' => '400', 'counted' => '850', 'note' => 'To bank'])->assertSessionHasNoErrors();
    expect(CashCheck::sole()->only('opening', 'expected', 'put_in', 'taken_out', 'counted'))
        ->toBe(['opening' => 100000, 'expected' => 85000, 'put_in' => 10000, 'taken_out' => 40000, 'counted' => 85000]);
    $this->get('/cash-check')->assertInertia(fn ($page) => $page->where('checks.data.0.difference', 0)->where('checks.data.0.changed', false));
});

test('4.3 difference needs a note', function () {
    cashCheck($this, ['counted' => '950'])->assertSessionHasErrors('note');
    cashCheck($this, ['put_in' => '10'])->assertSessionHasErrors('note');
    expect(CashCheck::count())->toBe(0);
    cashCheck($this, ['counted' => '950', 'note' => 'Lost'])->assertSessionHasNoErrors();
    expect(CashCheck::sole()->counted - CashCheck::sole()->expected)->toBe(-5000);
});

test('4.4 next days', function () {
    cashCheck($this, ['counted' => '950', 'note' => 'Lost']);
    addExpense($this, ['amount' => '1']);
    $this->travelTo('2026-11-26 10:00');
    addExpense($this);
    expect(($this->expected)('2026-11-26'))->toMatchArray(['opening' => 95000, 'from' => '2026-11-26', 'change' => -10000]);
    cashCheck($this)->assertSessionHasNoErrors();
    foreach (['2026-11-27', '2026-11-28', '2026-11-30'] as $day) {
        $this->travelTo("$day 10:00");
        addExpense($this);
    }
    expect(($this->expected)('2026-11-30'))->toMatchArray(['opening' => 85000, 'from' => '2026-11-27', 'change' => -30000]);
    cashCheck($this)->assertSessionHasNoErrors();
    expect(CashCheck::latest('id')->first()->expected)->toBe(55000);
});

test('4.5 dates', function () {
    cashCheck($this);
    cashCheck($this)->assertSessionHasErrors('date');
    cashCheck($this, ['date' => '2026-11-24'])->assertSessionHasErrors('date');
    cashCheck($this, ['date' => '2026-11-26'])->assertSessionHasErrors('date');
    cashCheck($this, ['date' => 'x', 'expected' => 1, 'counted' => '1'])->assertSessionHasErrors('date');
    expect(CashCheck::count())->toBe(1);
    $this->get('/cash-check?date=2026-11-20')->assertInertia(fn ($page) => $page->where('date', null));
});

test('4.6 changed after the check', function () {
    oneOfEach($this);
    cashCheck($this);
    $this->travelTo('2026-11-26 10:00');
    $before = ($this->expected)('2026-11-26');
    addExpense($this, ['date' => '2026-11-25', 'amount' => '7']);
    $changed = fn () => $this->get('/cash-check')->inertiaProps('checks')['data'][0];
    expect($changed())->toMatchArray(['changed' => true, 'now' => 114300])->and(($this->expected)('2026-11-26'))->toBe($before);
    $this->post('/expenses/' . Expense::latest('id')->first()->id . '/cancel', ['reason' => 'Wrong']);
    expect($changed()['changed'])->toBeFalse();
    $this->post('/bill/' . PaymentHistory::first()->id . '/cancel', ['reason' => 'Wrong'])->assertSessionHasNoErrors();
    expect($changed()['changed'])->toBeTrue();
});

test('4.7 cancelled records are left out', function () {
    oneOfEach($this);
    walkIn($this, $this->lunch, ['date' => '2026-11-25']);
    $this->post('/bill/' . PaymentHistory::first()->id . '/cancel', ['reason' => 'Wrong']);
    cancelGuest($this, App\Models\GuestMeal::sole()->id);
    cancelStock($this, App\Models\StockEntry::first()->id);
    $this->post('/expenses/' . Expense::first()->id . '/cancel', ['reason' => 'Wrong']);
    $this->post('/staff/entries/' . StaffEntry::first()->id . '/cancel', ['reason' => 'Wrong']);
    expect(($this->expected)()['change'])->toBe(0);
});

test('4.8 cancel', function () {
    cashCheck($this, ['counted' => '950', 'note' => 'Lost']);
    $this->travelTo('2026-11-26 10:00');
    cashCheck($this);
    [$first, $last] = CashCheck::orderBy('id')->pluck('id')->all();
    $cancel = fn ($id, $reason = 'Wrong') => $this->post("/cash-check/$id/cancel", ['reason' => $reason]);
    $cancel($first)->assertSessionHasErrors(['reason' => 'Only the latest check can be cancelled.']);
    $cancel($last, 'x')->assertSessionHasErrors('reason');
    $cancel($last)->assertSessionHasNoErrors();
    $cancel($last)->assertSessionHasErrors(['reason' => 'This was already cancelled.']);
    expect(($this->expected)('2026-11-26')['opening'])->toBe(95000);
    $cancel($first)->assertSessionHasNoErrors();
    expect(($this->expected)('2026-11-26')['opening'])->toBeNull();
    $cancel(99)->assertNotFound();
});

test('4.9 changed while the page was open', function () {
    $page = ($this->expected)();
    addExpense($this);
    cashCheck($this, ['expected' => 100000 + $page['change'], 'counted' => '1000'])->assertSessionHasErrors(['expected' => 'The amount has changed. Please check again.']);
    expect(CashCheck::count())->toBe(0);
});

test('4.10 modules off still count', function () {
    oneOfEach($this);
    module($this, 'staff', false);
    module($this, 'expenses', false);
    expect(($this->expected)()['change'])->toBe(15000);
});

test('4.11 opening', function () {
    cashCheck($this, ['opening' => ''])->assertSessionHasErrors('opening');
    cashCheck($this)->assertSessionHasNoErrors();
    $this->travelTo('2026-11-26 10:00');
    cashCheck($this, ['opening' => '5'])->assertSessionHasNoErrors();
    expect(CashCheck::latest('id')->first()->opening)->toBe(100000);
});

test('4.12 below zero', function () {
    addExpense($this, ['amount' => '2000']);
    cashCheck($this, ['counted' => '0', 'note' => 'Own money'])->assertSessionHasNoErrors();
    expect(CashCheck::sole()->expected)->toBe(-100000);
});

test('token and bad input', function () {
    $data = ['token' => token()];
    cashCheck($this, $data);
    cashCheck($this, $data + ['expected' => 100000, 'counted' => '1000'])->assertSessionHas('success');
    expect(CashCheck::count())->toBe(1);
    $this->travelTo('2026-11-26 10:00');
    foreach ([['counted' => 'x'], ['counted' => ''], ['counted' => ['1']], ['put_in' => '-1'], ['taken_out' => 'x'], ['expected' => 'x'], ['token' => 'x'], ['note' => str_repeat('a', 201)]] as $bad) {
        $this->post('/cash-check', $bad + ['date' => '2026-11-26', 'counted' => '1000', 'expected' => 100000, 'token' => token()])->assertSessionHasErrors(array_key_first($bad));
    }
    expect(CashCheck::count())->toBe(1);
});

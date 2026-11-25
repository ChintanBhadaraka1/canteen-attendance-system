<?php

use App\Models\ActivityLog;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->actingAs(makeAdmin()));

test('2.1 rent by bank', function () {
    addExpense($this, ['amount' => '5000.50', 'method' => 'bank', 'paid_to' => 'Owner'])->assertSessionHasNoErrors()->assertRedirect('/expenses');
    expect(Expense::sole()->only('amount', 'type_name', 'method', 'paid_to'))->toBe(['amount' => 500050, 'type_name' => 'Rent', 'method' => 'bank', 'paid_to' => 'Owner'])
        ->and(ActivityLog::sole()->only('action', 'summary'))->toBe(['action' => 'expense.added', 'summary' => 'Rent ₹5,000.50 on ' . today()->format('j F Y')]);
});

test('2.2 bad expenses', function ($data, $field) {
    addExpense($this, $data())->assertSessionHasErrors($field);
    expect(Expense::count())->toBe(0)->and(ActivityLog::count())->toBe(0);
})->with([
    [fn () => ['amount' => '0'], 'amount'], [fn () => ['amount' => '-1'], 'amount'], [fn () => ['amount' => '1.234'], 'amount'], [fn () => ['amount' => 'abc'], 'amount'],
    [fn () => ['amount' => '10000000.01'], 'amount'], [fn () => ['amount' => ['1']], 'amount'],
    [fn () => ['date' => today()->addDay()->toDateString()], 'date'], [fn () => ['date' => today()->subDays(367)->toDateString()], 'date'],
    [function () {
        App\Models\MenuOption::whereKey(optionId('Rent'))->update(['active' => false]);

        return [];
    }, 'type_id'],
    [fn () => ['type_id' => optionId('Veg')], 'type_id'], [fn () => ['type_id' => 9999], 'type_id'], [fn () => ['type_id' => ['x']], 'type_id'],
    [fn () => ['method' => 'card'], 'method'], [fn () => ['method' => ['cash']], 'method'], [fn () => ['token' => 'x'], 'token'],
    [fn () => ['paid_to' => str_repeat('a', 61)], 'paid_to'], [fn () => ['note' => str_repeat('a', 201)], 'note'],
]);

test('2.2 the limits are allowed', function () {
    addExpense($this, ['amount' => '10000000', 'date' => today()->subDays(366)->toDateString()])->assertSessionHasNoErrors();
    addExpense($this, ['amount' => '0.01'])->assertSessionHasNoErrors();
});

test('2.3 type renamed later', function () {
    addExpense($this);
    $this->put('/settings/options/' . optionId('Rent'), ['name' => 'Shop rent']);
    $this->get('/expenses')->assertInertia(fn ($page) => $page->where('expenses.data.0.type_name', 'Rent'));
});

test('2.4 token', function () {
    $data = ['token' => token()];
    addExpense($this, $data);
    addExpense($this, $data)->assertSessionHas('success');
    $this->actingAs(makeAdmin());
    addExpense($this, $data)->assertSessionHasErrors('expense');
    expect(Expense::count())->toBe(1)->and(ActivityLog::count())->toBe(1);
});

test('2.5 cancel', function () {
    addExpense($this);
    $id = Expense::sole()->id;
    $cancel = fn ($reason, $which = null) => $this->post('/expenses/' . ($which ?? $id) . '/cancel', ['reason' => $reason]);
    foreach (['', ' ab ', str_repeat('a', 201), ['abc']] as $reason) {
        $cancel($reason)->assertSessionHasErrors('reason');
    }
    $cancel('Twice')->assertSessionHasNoErrors();
    expect(Expense::sole()->cancel_reason)->toBe('Twice')->and(Expense::sole()->cancelled_at)->not->toBeNull()->and(Expense::sole()->cancelled_by)->toBe(auth()->id())
        ->and(ActivityLog::where('action', 'expense.cancelled')->count())->toBe(1);
    $cancel('Again')->assertSessionHasErrors(['reason' => 'This was already cancelled.']);
    $cancel('Again', 99)->assertNotFound();
});

test('2.6 list', function () {
    addExpense($this, ['amount' => '10', 'date' => today()->subDays(3)->toDateString()]);
    addExpense($this, ['amount' => '20', 'type_id' => optionId('Electricity'), 'method' => 'upi']);
    addExpense($this, ['amount' => '40', 'paid_to' => 'Suresh']);
    $this->post('/expenses/' . Expense::latest('id')->first()->id . '/cancel', ['reason' => 'Wrong']);
    $list = function ($query) {
        $props = $this->get("/expenses?$query")->assertOk()->inertiaProps();
        expect(array_sum(array_column($props['totals'], 'total')))->toBe($props['total'])
            ->and(collect($props['expenses']['data'])->where('cancelled', false)->sum('amount'))->toBe($props['total']);

        return [collect($props['expenses']['data'])->pluck('amount')->all(), $props['total']];
    };
    expect($list(''))->toBe([[4000, 2000, 1000], 3000])->and($list('from=' . today()->toDateString()))->toBe([[4000, 2000], 2000])
        ->and($list('to=' . today()->subDay()->toDateString()))->toBe([[1000], 1000])->and($list('type=' . optionId('Electricity')))->toBe([[2000], 2000])
        ->and($list('method=cash'))->toBe([[4000, 1000], 1000])->and($list('show=cancelled'))->toBe([[4000], 0])->and($list('show=saved'))->toBe([[2000, 1000], 3000])
        ->and($list('search=sures'))->toBe([[4000], 0])->and($list('type[]=1&method=x&from=x&show[]=1'))->toBe([[4000, 2000, 1000], 3000]);
});

test('2.7 csv', function () {
    addExpense($this, ['amount' => '1250.50', 'paid_to' => '=x', 'note' => '=y']);
    $csv = $this->get('/expenses/download')->assertOk()->streamedContent();
    expect($csv)->toContain("Rent,1250.50,Cash,'=x,'=y")->not->toContain('₹');
});

test('2.8 all or nothing', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('log'));
    addExpense($this)->assertStatus(500);
    expect(Expense::count())->toBe(0);
});

test('2.9 query count', function () {
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/expenses')->assertOk();

        return count(DB::getQueryLog());
    };
    foreach (range(1, 10) as $i) {
        addExpense($this);
    }
    $before = $queries();
    foreach (range(1, 90) as $i) {
        addExpense($this);
    }
    expect($queries())->toBe($before);
});

test('pages', function () {
    $this->get('/expenses/create')->assertInertia(fn ($page) => $page->component('Expenses/Form')->has('types', 5)->has('token'));
});

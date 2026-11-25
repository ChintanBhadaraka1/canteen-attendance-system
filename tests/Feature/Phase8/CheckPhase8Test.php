<?php

use App\Models\ActivityLog;
use App\Models\CashCheck;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

afterEach(fn () => DB::setDefaultConnection('sqlite'));

test('7.1 problems', function ($break, $text) {
    $this->travelTo('2026-11-25 10:00');
    $this->actingAs(makeAdmin());
    module($this, 'staff');
    module($this, 'cash');
    $staff = hire($this);
    staffEntry($this, $staff, 'payment', '2026-11', '9000');
    addExpense($this);
    cashCheck($this, ['opening' => '20000'])->assertSessionHasNoErrors();
    $this->travelTo('2026-11-26 10:00');
    cashCheck($this)->assertSessionHasNoErrors();
    $this->artisan('canteen:check-money')->assertExitCode(0);
    $break();
    $dump = fn () => DB::table('staff_entries')->get() . DB::table('cash_checks')->get() . DB::table('expenses')->get();
    $before = $dump();
    $this->artisan('canteen:check-money')->expectsOutputToContain($text)->assertExitCode(1);
    expect($dump())->toBe($before);
})->with([
    [fn () => DB::table('staff_salary_rates')->update(['amount' => 1]), 'staff 1: 2026-11 paid 900000, due 1'],
    [fn () => DB::table('staff_entries')->update(['month' => '2026-08-01']), 'staff entry 1: month outside employment'],
    [fn () => DB::table('expenses')->update(['cancelled_at' => now()]), 'expenses 1: cancelled without reason or time'],
    [fn () => DB::table('staff_entries')->update(['cancel_reason' => 'x']), 'staff_entries 1: cancelled without reason or time'],
    [fn () => DB::table('cash_checks')->where('id', 2)->update(['cancel_reason' => 'x']), 'cash_checks 2: cancelled without reason or time'],
    [fn () => DB::table('cash_checks')->where('id', 2)->update(['opening' => 1]), 'cash check 2: opening 1, previous counted'],
]);

test('7.1 changed after a check is no problem', function () {
    $this->actingAs(makeAdmin());
    module($this, 'cash');
    cashCheck($this);
    addExpense($this);
    $this->artisan('canteen:check-money')->assertExitCode(0);
});

test('7.2 and 7.4 migrate, roll back, migrate', function () {
    separateDatabase();
    $options = fn () => DB::table('menu_options')->whereNotIn('kind', ['expense_type', 'staff_job'])->orderBy('id')->get()->toJson();
    $before = $options();
    Artisan::call('migrate:rollback', ['--step' => 3]);
    expect(DB::getSchemaBuilder()->hasTable('app_settings'))->toBeFalse()->and($options())->toBe($before)->and(DB::table('menu_options')->where('kind', 'expense_type')->count())->toBe(0)
        ->and(Artisan::call('canteen:check-money'))->toBe(0);
    Artisan::call('migrate');
    expect($options())->toBe($before)->and(DB::table('menu_options')->where('kind', 'expense_type')->count())->toBe(5)->and(DB::table('app_settings')->count())->toBe(3);
});

test('7.3 one change record each', function () {
    $this->travelTo('2026-11-25 10:00');
    $this->actingAs(makeAdmin());
    $one = function (string $type, callable $do) {
        $before = ActivityLog::count();
        $do();
        expect(ActivityLog::count())->toBe($before + 1);
        $this->get("/changes?type=$type")->assertInertia(fn ($page) => $page->where('changes.data.0.id', ActivityLog::max('id')));
    };
    $one('settings', fn () => module($this, 'staff'));
    $one('settings', fn () => module($this, 'cash'));
    $one('expense', fn () => addExpense($this));
    $one('expense', fn () => $this->post('/expenses/1/cancel', ['reason' => 'Wrong']));
    $one('staff', fn () => hire($this));
    $staff = App\Models\Staff::sole();
    $one('staff', fn () => staffEntry($this, $staff, 'payment', '2026-11', '10'));
    $one('staff', fn () => $this->post('/staff/entries/1/cancel', ['reason' => 'Wrong']));
    $one('staff', fn () => $this->post("/staff/{$staff->id}/rates", ['month' => '2026-12', 'amount' => '1']));
    $one('staff', fn () => $this->put("/staff/{$staff->id}", ['name' => 'Other', 'joined_on' => '2026-09-15']));
    $one('staff', fn () => $this->patch("/staff/{$staff->id}/status", ['status' => 'left', 'date' => '2026-11-20']));
    $one('staff', fn () => $this->patch("/staff/{$staff->id}/status", ['status' => 'working', 'date' => '2026-11-22']));
    $one('cash', fn () => cashCheck($this));
    $one('cash', fn () => $this->post('/cash-check/' . CashCheck::sole()->id . '/cancel', ['reason' => 'Wrong']));
});

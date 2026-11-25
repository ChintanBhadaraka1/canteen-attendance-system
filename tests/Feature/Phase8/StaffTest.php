<?php

use App\Models\ActivityLog;
use App\Models\Staff;
use App\Models\StaffEntry;
use App\Models\StaffSalaryRate;
use App\Services\SalaryService;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00');
    $this->actingAs(makeAdmin());
    module($this, 'staff');
    $this->staff = hire($this);
});

function salary(Staff $staff, string $month): array
{
    return app(SalaryService::class)->month($staff->fresh(), $month);
}

function staffStatus($test, string $status, string $date)
{
    return $test->patch("/staff/{$test->staff->id}/status", compact('status', 'date'));
}

function changeRate($test, string $month, string $amount)
{
    return $test->post("/staff/{$test->staff->id}/rates", compact('month', 'amount'));
}

test('3.1 add staff', function () {
    expect(StaffSalaryRate::sole()->only('from_month', 'amount'))->toBe(['from_month' => '2026-09-01', 'amount' => 900000])
        ->and(salary($this->staff, '2026-09')['due'])->toBe(900000)->and(salary($this->staff, '2026-08')['due'])->toBe(0);
    $this->get('/staff')->assertInertia(fn ($page) => $page->where('staff.0.name', 'Ramesh Cook')->where('staff.0.left', 900000)->where('staff.0.working', true));
    $this->get("/staff/{$this->staff->id}")->assertInertia(fn ($page) => $page->has('months', 3)->where('months.0.month', '2026-11'));
    $this->get("/staff/{$this->staff->id}/edit")->assertOk();
    $this->get('/staff/create')->assertOk();
    $this->get('/staff/99')->assertNotFound();
});

test('3.2 pay in parts', function () {
    staffEntry($this, $this->staff, 'payment', '2026-11', '4000')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-11')['left'])->toBe(500000);
    staffEntry($this, $this->staff, 'payment', '2026-11', '5000')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-11')['left'])->toBe(0);
    staffEntry($this, $this->staff, 'payment', '2026-11', '1')->assertSessionHasErrors(['entry' => 'November 2026 would have more paid (₹9,001.00) than due (₹9,000.00).']);
    expect(StaffEntry::count())->toBe(2);
});

test('3.3 rate from December', function () {
    changeRate($this, '2026-12', '10000')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-11')['due'])->toBe(900000)->and(salary($this->staff, '2026-12')['due'])->toBe(1000000);
    $this->get("/staff/{$this->staff->id}")->assertInertia(fn ($page) => $page->has('rates', 2)->where('rates.0.amount', 1000000));
    changeRate($this, '2026-12', '11000');
    expect(StaffSalaryRate::count())->toBe(2)->and(ActivityLog::latest('id')->first()->summary)->toBe('Salary of Ramesh Cook from December 2026: ₹11,000.00 (before ₹10,000.00)');
});

test('3.4 bonus and deduction', function () {
    staffEntry($this, $this->staff, 'bonus', '2026-11', '1000');
    staffEntry($this, $this->staff, 'payment', '2026-11', '10000')->assertSessionHasNoErrors();
    staffEntry($this, $this->staff, 'deduction', '2026-10', '500')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-11')['left'])->toBe(0)->and(salary($this->staff, '2026-10')['due'])->toBe(850000);
});

test('3.5 advance', function () {
    staffEntry($this, $this->staff, 'payment', '2026-12', '2000')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-12')['paid'])->toBe(200000);
    staffEntry($this, $this->staff, 'payment', '2027-11', '1')->assertSessionHasNoErrors();
    staffEntry($this, $this->staff, 'payment', '2027-12', '1')->assertSessionHasErrors('month');
    staffEntry($this, $this->staff, 'payment', '2026-08', '1')->assertSessionHasErrors('month');
});

test('3.6 rate lowered below what was paid', function () {
    staffEntry($this, $this->staff, 'payment', '2026-11', '9000');
    changeRate($this, '2026-11', '8000')->assertSessionHasErrors(['amount' => 'November 2026 would have more paid (₹9,000.00) than due (₹8,000.00).']);
    expect(StaffSalaryRate::count())->toBe(1);
});

test('3.7 deduction or cancelled bonus after payment', function () {
    staffEntry($this, $this->staff, 'bonus', '2026-11', '1000');
    $bonus = StaffEntry::sole()->id;
    staffEntry($this, $this->staff, 'payment', '2026-11', '10000');
    staffEntry($this, $this->staff, 'deduction', '2026-11', '1')->assertSessionHasErrors('entry');
    $this->post("/staff/entries/$bonus/cancel", ['reason' => 'Wrong'])->assertSessionHasErrors('reason');
    expect(StaffEntry::whereNull('cancelled_at')->count())->toBe(2);
});

test('3.8 cancel a payment', function () {
    staffEntry($this, $this->staff, 'payment', '2026-11', '9000');
    $cancel = fn ($id, $reason = 'Wrong') => $this->post("/staff/entries/$id/cancel", ['reason' => $reason]);
    $id = StaffEntry::sole()->id;
    $cancel($id, 'x')->assertSessionHasErrors('reason');
    $cancel($id)->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-11')['left'])->toBe(900000);
    $cancel($id)->assertSessionHasErrors(['reason' => 'This was already cancelled.']);
    $cancel(99)->assertNotFound();
});

test('3.9 deduction more than the rate', function () {
    staffEntry($this, $this->staff, 'deduction', '2026-11', '9000.01')->assertSessionHasErrors('entry');
    staffEntry($this, $this->staff, 'deduction', '2026-11', '9000')->assertSessionHasNoErrors();
});

test('3.10 left and back', function () {
    staffEntry($this, $this->staff, 'payment', '2026-12', '10');
    staffStatus($this, 'left', '2026-11-10')->assertSessionHasErrors(['date' => 'There are entries for December 2026. Cancel them first.']);
    $this->post('/staff/entries/' . StaffEntry::sole()->id . '/cancel', ['reason' => 'Left']);
    staffStatus($this, 'left', '2026-11-10')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-11')['due'])->toBe(900000)->and(salary($this->staff, '2026-12')['due'])->toBe(0);
    staffEntry($this, $this->staff, 'payment', '2026-12', '10')->assertSessionHasErrors('month');
    staffStatus($this, 'left', '2026-11-12')->assertSessionHasErrors('date');
    staffStatus($this, 'working', '2026-11-10')->assertSessionHasErrors('date');
    $this->travelTo('2027-03-20 10:00');
    staffStatus($this, 'working', '2027-02-05')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2027-01')['due'])->toBe(0)->and(salary($this->staff, '2027-02')['due'])->toBe(900000);
    staffStatus($this, 'working', '2027-03-01')->assertSessionHasErrors('date');
    staffStatus($this, 'left', '2027-02-01')->assertSessionHasErrors('date');
    staffStatus($this, 'left', '2027-03-21')->assertSessionHasErrors('date');
    staffStatus($this, 'x', '2027-03-01')->assertSessionHasErrors('status');
    expect(ActivityLog::whereIn('action', ['staff.left', 'staff.returned'])->count())->toBe(2);
});

test('3.10 joining date', function () {
    staffEntry($this, $this->staff, 'payment', '2026-10', '10');
    $save = fn ($date) => $this->put("/staff/{$this->staff->id}", ['name' => 'Ramesh', 'joined_on' => $date]);
    $save('2026-11-01')->assertSessionHasErrors('joined_on');
    $save('2026-10-31')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-09')['due'])->toBe(0);
    $save('2026-08-01')->assertSessionHasNoErrors();
    expect(salary($this->staff, '2026-08')['due'])->toBe(900000)->and($this->staff->fresh()->name)->toBe('Ramesh');
});

test('3.10 joining date of a second period', function () {
    staffStatus($this, 'left', '2026-10-10');
    staffStatus($this, 'working', '2026-11-05');
    $this->put("/staff/{$this->staff->id}", ['name' => 'Ramesh', 'joined_on' => '2026-10-10'])->assertSessionHasErrors('joined_on');
    $this->put("/staff/{$this->staff->id}", ['name' => 'Ramesh', 'joined_on' => '2026-10-11'])->assertSessionHasNoErrors();
});

test('3.11 bad staff', function ($data, $field) {
    $this->post('/staff', $data + ['name' => 'Asha', 'phone' => '', 'job_id' => '', 'joined_on' => '2026-11-01', 'salary' => '1000'])->assertSessionHasErrors($field);
    expect(Staff::count())->toBe(1);
})->with([
    [['name' => 'A'], 'name'], [['name' => str_repeat('a', 61)], 'name'], [['name' => ['x']], 'name'], [['phone' => '123'], 'phone'], [['phone' => '98765432101'], 'phone'], [['phone' => ['x']], 'phone'],
    [['job_id' => 1], 'job_id'], [['joined_on' => '2026-11-26'], 'joined_on'], [['joined_on' => '1976-11-24'], 'joined_on'], [['joined_on' => 'x'], 'joined_on'],
    [['salary' => '0'], 'salary'], [['salary' => 'x'], 'salary'], [['salary' => ['1']], 'salary'], [['salary' => '10000000.01'], 'salary'],
]);

test('3.11 bad entries', function ($data, $field) {
    $this->post("/staff/{$this->staff->id}/entries", $data + ['type' => 'payment', 'month' => '2026-11', 'date' => '2026-11-25', 'amount' => '10', 'method' => 'cash', 'token' => token()])->assertSessionHasErrors($field);
    expect(StaffEntry::count())->toBe(0);
})->with([
    [['type' => 'x'], 'type'], [['type' => ['x']], 'type'], [['month' => '2026-13'], 'month'], [['month' => ['x']], 'month'], [['date' => '2026-11-26'], 'date'], [['amount' => '0'], 'amount'],
    [['method' => ''], 'method'], [['method' => 'x'], 'method'], [['type' => 'bonus'], 'note'], [['type' => 'deduction', 'note' => ' '], 'note'], [['token' => ''], 'token'],
]);

test('3.11 bad rates', function ($month, $amount, $field) {
    changeRate($this, $month, $amount)->assertSessionHasErrors($field);
    expect(StaffSalaryRate::count())->toBe(1);
})->with([['2026-08', '10', 'month'], ['x', '10', 'month'], ['2027-12', '10', 'month'], ['2026-12', '0', 'amount'], ['2026-12', 'x', 'amount']]);

test('3.12 token', function () {
    $data = ['token' => token()];
    staffEntry($this, $this->staff, 'payment', '2026-11', '9000', $data);
    staffEntry($this, $this->staff, 'payment', '2026-11', '9000', $data)->assertSessionHas('success');
    expect(StaffEntry::count())->toBe(1);
    $this->actingAs(makeAdmin());
    staffEntry($this, $this->staff, 'payment', '2026-11', '9000', $data)->assertSessionHasErrors('entry');
});

test('3.13 no phone in the change record', function () {
    $this->put("/staff/{$this->staff->id}", ['name' => 'Ramesh', 'phone' => '9123456789', 'joined_on' => '2026-09-15']);
    expect($this->staff->fresh()->phone)->toBe('9123456789')->and(ActivityLog::where('action', 'staff.changed')->sole()->summary)->toContain('phone');
    foreach (ActivityLog::all() as $row) {
        expect($row->toJson())->not->toContain('9876543210')->not->toContain('9123456789');
    }
});

test('3.14 random walk', function () {
    mt_srand(8);
    $done = 0;
    foreach (range(1, 200) as $step) {
        $month = sprintf('2026-%02d', mt_rand(9, 12));
        $amount = (string) mt_rand(1, 6000);
        match (mt_rand(0, 5)) {
            0, 1 => staffEntry($this, $this->staff, 'payment', $month, $amount),
            2 => staffEntry($this, $this->staff, 'bonus', $month, $amount),
            3 => staffEntry($this, $this->staff, 'deduction', $month, $amount),
            4 => changeRate($this, $month, (string) mt_rand(5000, 15000)),
            5 => $this->post('/staff/entries/' . (StaffEntry::inRandomOrder(mt_rand())->value('id') ?? 1) . '/cancel', ['reason' => 'Wrong']),
        };
        $done += session()->has('errors') ? 0 : 1;
        session()->flush();
        foreach (['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'] as $m) {
            $s = salary($this->staff, $m);
            expect($s['left'])->toBe($s['due'] - $s['paid'])->toBeGreaterThanOrEqual(0);
        }
        $this->actingAs(App\Models\User::first());
    }
    expect($done)->toBeGreaterThan(40);
});

test('3.15 all or nothing', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('log'));
    staffEntry($this, $this->staff, 'payment', '2026-11', '9000')->assertStatus(500);
    $this->post('/staff', ['name' => 'Asha', 'joined_on' => '2026-11-01', 'salary' => '1000'])->assertStatus(500);
    changeRate($this, '2026-12', '1')->assertStatus(500);
    staffStatus($this, 'left', '2026-11-20')->assertStatus(500);
    expect(StaffEntry::count())->toBe(0)->and(Staff::count())->toBe(1)->and(StaffSalaryRate::count())->toBe(1)->and($this->staff->periods()->sole()->left_on)->toBeNull();
});

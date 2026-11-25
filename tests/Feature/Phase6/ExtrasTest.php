<?php

use App\Models\AttendanceExtra;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    moneySetup($this);
    $this->curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    $this->papad = dish('Papad', ['is_extra' => '1', 'price' => 10]);
});

function withExtras($test, array $items, array $data = []): StudentAttendance
{
    return markMeal($test, $test->lunch, $data + ['include_extra' => 'yes', 'extra_items' => $items], true);
}

function csvOf($test): string
{
    return $test->get('/student-attendance/download?from=2026-01-01&to=2026-01-31')->streamedContent();
}

function historyOf($test)
{
    return $test->get("/student-attendance/show/{$test->student->id}?from=2026-01-01&to=2026-01-31");
}

test('5.1 rows are kept', function () {
    $entry = withExtras($this, [$this->curd->id => 2, $this->papad->id => 1]);
    expect(AttendanceExtra::orderBy('id')->get(['name', 'quantity', 'price'])->toArray())->toBe([
        ['name' => 'Curd', 'quantity' => 2, 'price' => 15], ['name' => 'Papad', 'quantity' => 1, 'price' => 10],
    ]);
    expect($entry)->extra_amount->toEqual(40)->extra_meal_id->toBeNull()->and(Payment::sole()->amount)->toBe(110);
    historyOf($this)->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.extras', [['name' => 'Curd', 'quantity' => 2, 'total' => 30], ['name' => 'Papad', 'quantity' => 1, 'total' => 10]]));
    expect(csvOf($this))->toContain('"Curd x 2, Papad x 1",40.00');
});

test('5.2 no rows', function () {
    withExtras($this, [$this->curd->id => 0]);
    withExtras($this, [$this->curd->id => 2], ['include_extra' => 'no', 'date' => '2026-01-11']);
    expect(StudentAttendance::count())->toBe(2)->and(AttendanceExtra::count())->toBe(0);
});

test('5.3 an old entry', function () {
    $entry = markMeal($this, $this->lunch, [], true);
    $entry->update(['extra_meal_id' => json_encode([$this->curd->id, $this->papad->id]), 'extra_amount' => 25]);
    historyOf($this)->assertInertia(fn (Assert $page) => $page->where('rows.data.0.extras.0', ['name' => 'Curd', 'quantity' => null, 'total' => null]));
    expect(csvOf($this))->toContain('"Curd, Papad",25.00');
});

test('5.4 deleting', function () {
    $entry = withExtras($this, [$this->curd->id => 2, $this->papad->id => 1]);
    $this->post('/student-attendance/delete', ['id' => $entry->id]);
    expect(AttendanceExtra::count())->toBe(0)->and(Payment::sole()->amount)->toBe(0);
});

test('5.5 the item changes later', function () {
    withExtras($this, [$this->curd->id => 2]);
    $this->put("/menus/{$this->curd->id}", ['name' => 'Dahi', 'is_extra' => 'on', 'price' => 99]);
    $this->delete("/menus/{$this->curd->id}")->assertSessionHas('success');
    expect(csvOf($this))->toContain('"Curd x 2",30.00')->and(AttendanceExtra::sole()->menu_id)->toBeNull();
    historyOf($this)->assertInertia(fn (Assert $page) => $page->where('rows.data.0.extras.0.total', 30));
});

test('5.6 formula name', function () {
    $this->curd->update(['name' => '=x']);
    withExtras($this, [$this->curd->id => 1]);
    expect(csvOf($this))->toContain("\"'=x x 1\"");
});

test('5.7 an error on the second row saves nothing', function () {
    AttendanceExtra::creating(function ($row) {
        throw_if($row->name === 'Papad', new Exception('no'));
    });
    markMeal($this, $this->lunch, ['include_extra' => 'yes', 'extra_items' => [$this->curd->id => 1, $this->papad->id => 1]])->assertServerError();
    expect(StudentAttendance::count() + AttendanceExtra::count() + Payment::count())->toBe(0);
});

test('5.8 money check', function () {
    $entry = withExtras($this, [$this->curd->id => 2]);
    $this->artisan('canteen:check-money')->assertExitCode(0);
    AttendanceExtra::query()->update(['quantity' => 3]);
    expect(Artisan::call('canteen:check-money'))->toBe(1);
    expect(Artisan::output())->toContain("entry {$entry->id}: extra amount 30, extra items 45");
    AttendanceExtra::query()->update(['quantity' => 2]);
    $entry->update(['extra_meal_id' => '[1]']);
    expect(Artisan::call('canteen:check-money'))->toBe(1);
    expect(Artisan::output())->toContain("entry {$entry->id}: old and new extra items");
});

test('5.9 pay, cancel, delete', function () {
    $entry = withExtras($this, [$this->curd->id => 2]);
    payBillOf($this, 100);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong amount']);
    expect(parts())->toBe([100, 0, 0]);
    $this->post('/student-attendance/delete', ['id' => $entry->id]);
    expect(parts())->toBe([0, 0, 0])->and(AttendanceExtra::count())->toBe(0);
});

test('5.10 a fixed number of queries', function () {
    $count = function (Closure $call) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();

        return count(DB::getQueryLog());
    };
    $both = fn () => [$count(fn () => historyOf($this)), $count(fn () => csvOf($this))];
    withExtras($this, [$this->curd->id => 1]);
    $both();
    $one = $both();
    foreach (range(11, 29) as $day) {
        withExtras($this, [$this->curd->id => 1, $this->papad->id => 1], ['date' => "2026-01-$day"]);
    }
    expect($both())->toBe($one);
});

test('items that are not extra are refused', function () {
    markMeal($this, $this->lunch, ['include_extra' => 'yes', 'extra_items' => [dish('Dal')->id => 1]])->assertSessionHas('error');
    expect(StudentAttendance::count())->toBe(0);
});

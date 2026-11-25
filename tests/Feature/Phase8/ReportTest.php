<?php

use App\Models\Expense;
use App\Models\PaymentHistory;
use App\Models\StaffEntry;
use App\Models\StockEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00');
    moneySetup($this);
    module($this, 'staff');
    $this->rice = stockItem('Rice');
    $this->day = ['date' => '2026-11-25'];
    $this->month = function (string $query = '', int $which = 0) {
        $month = $this->get("/reports/summary?$query")->assertOk()->inertiaProps('months')[$which];

        return ['left' => collect($month['left'])->pluck('amount', 'key')->all(), 'right' => collect($month['right'])->pluck('amount', 'key')->all()] + $month;
    };
});

test('6.1 one of each', function () {
    markMeal($this, $this->lunch, $this->day);
    payBillOf($this, 60);
    cardGuest($this, $this->lunch, $this->day + ['plates' => 2]);
    walkIn($this, $this->lunch, $this->day);
    buy($this, [[$this->rice, '10', '100']]);
    stockOut($this, 'use', [[$this->rice, '4']]);
    stockOut($this, 'waste', [[$this->rice, '1']]);
    countStock($this, [[$this->rice, '4']]);
    addExpense($this, ['amount' => '12.50']);
    staffEntry($this, hire($this, ['salary' => '100']), 'payment', '2026-11', '30');
    $m = ($this->month)();
    expect($m['left'])->toBe(['students' => 6000, 'walk_ins' => 7000, 'purchases' => -10000, 'expenses' => -1250, 'salaries' => -3000])->and($m['left_over'])->toBe(-1250)
        ->and($m['right'])->toBe(['meals' => 28000, 'used' => -4000, 'wasted' => -1000, 'counts' => -1000, 'expenses' => -1250, 'salaries' => -10000])->and($m['profit'])->toBe(10750)
        ->and($m['served'])->toBe(4)->and($m['cost_per_meal'])->toBe(1000);
});

test('6.2 bought and used in other months', function () {
    buy($this, [[$this->rice, '10', '100']], ['date' => '2026-10-31']);
    buy($this, [[$this->rice, '10', '100']]);
    stockOut($this, 'use', [[$this->rice, '15']]);
    $m = ($this->month)();
    expect($m['left']['purchases'])->toBe(-10000)->and($m['right']['used'])->toBe(-15000);
    expect(($this->month)('', 1)['left']['purchases'])->toBe(-10000)->and(($this->month)('', 1)['right']['used'])->toBe(0);
});

test('6.3 salaries', function () {
    $staff = hire($this, ['salary' => '1000']);
    staffEntry($this, $staff, 'payment', '2026-10', '400', ['date' => '2026-11-03']);
    staffEntry($this, $staff, 'payment', '2026-12', '200');
    staffEntry($this, $staff, 'deduction', '2026-11', '100');
    expect(($this->month)()['left']['salaries'])->toBe(-60000)->and(($this->month)()['right']['salaries'])->toBe(-90000)
        ->and(($this->month)('month=2026-10')['left']['salaries'])->toBe(0)->and(($this->month)('month=2026-10')['right']['salaries'])->toBe(-100000);
});

test('6.4 cancelled', function () {
    markMeal($this, $this->lunch, $this->day);
    payBillOf($this, 70);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    cardGuest($this, $this->lunch, $this->day);
    walkIn($this, $this->lunch, $this->day);
    App\Models\GuestMeal::pluck('id')->each(fn ($id) => cancelGuest($this, $id));
    buy($this, [[$this->rice, '10', '100']]);
    stockOut($this, 'use', [[$this->rice, '4']]);
    cancelStock($this, StockEntry::latest('id')->first()->id);
    cancelStock($this, StockEntry::first()->id);
    addExpense($this);
    $this->post('/expenses/' . Expense::sole()->id . '/cancel', ['reason' => 'Wrong']);
    $staff = hire($this, ['salary' => '100', 'joined_on' => '2026-11-01']);
    staffEntry($this, $staff, 'bonus', '2026-11', '30');
    staffEntry($this, $staff, 'payment', '2026-11', '30');
    StaffEntry::orderByDesc('id')->pluck('id')->each(fn ($id) => $this->post("/staff/entries/$id/cancel", ['reason' => 'Wrong'])->assertSessionHasNoErrors());
    $m = ($this->month)();
    expect(array_sum($m['left']))->toBe(0)->and($m['right'])->toBe(['meals' => 7000, 'used' => 0, 'wasted' => 0, 'counts' => 0, 'expenses' => 0, 'salaries' => -10000]);
});

test('6.5 count up', function () {
    buy($this, [[$this->rice, '10', '100']]);
    countStock($this, [[$this->rice, '12']]);
    expect(($this->month)()['right']['counts'])->toBe(2000)->and(($this->month)()['profit'])->toBe(2000);
});

test('6.6 no meals, no use', function () {
    expect(($this->month)()['cost_per_meal'])->toBeNull()->and(($this->month)()['no_use'])->toBeTrue();
    markMeal($this, $this->lunch, $this->day);
    expect(($this->month)()['no_use'])->toBeTrue()->and(($this->month)()['cost_per_meal'])->toBe(0);
});

test('6.7 month ends', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-12-31 23:59', 'Asia/Kolkata'));
    addExpense($this, ['amount' => '1']);
    markMeal($this, $this->lunch, ['date' => '2026-12-31']);
    payBillOf($this, 70);
    $this->travelTo(Carbon\Carbon::parse('2027-01-01 00:01', 'Asia/Kolkata'));
    addExpense($this, ['amount' => '2']);
    expect(($this->month)()['left']['expenses'])->toBe(-200)->and(($this->month)()['month'])->toBe('2027-01')
        ->and(($this->month)('', 1)['left'])->toMatchArray(['expenses' => -100, 'students' => 7000])->and(($this->month)('', 1)['month'])->toBe('2026-12');
});

test('6.8 old payment', function () {
    markMeal($this, $this->lunch, $this->day);
    payBillOf($this, 70);
    PaymentHistory::sole()->update(['before_amount' => null]);
    expect(($this->month)()['left']['students'])->toBeNull()->and(($this->month)()['left_over'])->toBeNull()->and(($this->month)()['right']['meals'])->toBe(7000);
    expect($this->get('/reports/summary/download?month=2026-11')->streamedContent())->toContain('Not available');
});

test('6.9 money due', function () {
    $make = function (string $name, array $data = []) {
        $this->student = makeStudent($data + ['name' => $name, 'middle_name' => null, 'last_name' => 'P', 'collage_name' => 'North']);
        markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    };
    $make('Asha');
    payBillOf($this, 20);
    cardGuest($this, $this->lunch, ['date' => '2026-11-07']);
    $make('Clear');
    payBillOf($this, 70);
    $make('Advance');
    payBillOf($this, 100);
    $make('Left', ['collage_name' => 'South']);
    $this->student->forceFill(['left_on' => '2026-11-10'])->save();
    $make('Gone');
    $this->student->delete();
    $due = $this->get('/reports/due')->inertiaProps();
    expect(array_column($due['rows'], 'name'))->toBe(['Asha P', 'Gone P', 'Left P'])->and(array_column($due['rows'], 'due'))->toBe([120, 70, 70])
        ->and($due['total'])->toBe(260)->and($due['deleted'])->toBe(70)->and($due['rows'][0])->toMatchArray(['last_payment' => '2026-11-25', 'last_meal' => '2026-11-07', 'tag' => null])
        ->and(array_column($due['rows'], 'tag'))->toBe([null, 'Deleted', 'Left']);
    expect($this->get('/dashboard')->inertiaProps('due')['total'] + $due['deleted'])->toBe($due['total']);
    $names = fn ($query) => array_column($this->get("/reports/due?$query")->assertOk()->inertiaProps('rows'), 'name');
    expect($names('college=South'))->toBe(['Left P'])->and($names('search=ash'))->toBe(['Asha P'])->and($names('sort=due&dir=asc')[2])->toBe('Asha P')
        ->and($names('sort[]=x&college[]=x&search[]=x&dir=x'))->toHaveCount(3);
    expect($this->get('/reports/due?college=South')->inertiaProps('total'))->toBe(70);
});

test('6.10 meals and waste', function () {
    $second = makeStudent(['collage_name' => 'South']);
    $this->student->forceFill(['collage_name' => 'North'])->save();
    markMeal($this, $this->lunch, $this->day);
    markMeal($this, $this->dinner, ['date' => '2026-11-24']);
    cardGuest($this, $this->lunch, $this->day + ['plates' => 2]);
    walkIn($this, $this->lunch, $this->day + ['plates' => 4]);
    $this->student = $second;
    markMeal($this, $this->lunch, $this->day);
    $second->delete();
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    walkIn($this, $this->dinner, $this->day + ['extras' => [$curd->id => 2]])->assertSessionHasNoErrors();
    buy($this, [[$this->rice, '10', '100']]);
    stockOut($this, 'waste', [[$this->rice, '2']]);
    $r = $this->get('/reports/meals')->assertOk()->inertiaProps();
    foreach (['days', 'meals', 'colleges'] as $list) {
        foreach (['own' => 3, 'card' => 2, 'walk_in' => 5] as $kind => $total) {
            expect(array_sum(array_column($r[$list], $kind)))->toBe($total);
        }
    }
    expect($r['totals'])->toBe(['own' => 3, 'card' => 2, 'walk_in' => 5])->and(collect($r['colleges'])->firstWhere('name', 'Walk-ins'))->toMatchArray(['own' => 0, 'walk_in' => 5])
        ->and($r['extras'])->toBe([['name' => 'Curd', 'quantity' => 2, 'amount' => 30]])
        ->and($r['waste'])->toBe([['reason' => 'Spoiled', 'item' => 'Rice', 'unit' => 'kg', 'quantity' => 2000, 'cost' => 2000]]);
});

test('6.11 bad values', function ($query) {
    expect(($this->month)($query)['month'])->toBe('2026-11');
    $this->get("/reports/meals?$query")->assertOk()->assertInertia(fn ($page) => $page->where('filters.from', '2026-11-01')->where('filters.to', '2026-11-30'));
    $this->get("/reports/due?$query")->assertOk();
})->with(['month=2026-13&from=x&to=y', 'month[]=x&from[]=x', 'month=2099-01&from=2026-11-05', 'month=']);

test('6.12 csv', function () {
    $this->student->forceFill(['name' => '=x', 'middle_name' => null, 'collage_name' => '=North'])->save();
    markMeal($this, $this->lunch, $this->day);
    expect($this->get('/reports/due/download')->assertOk()->streamedContent())->toContain("'=x Patel")->toContain("'=North")->not->toContain('₹');
    expect($this->get('/reports/meals/download')->assertOk()->streamedContent())->toContain("'=North");
    expect($this->get('/reports/summary/download')->assertOk()->streamedContent())->toContain('70.00')->not->toContain('₹');
});

test('6.13 query count', function () {
    $add = function (int $n) {
        foreach (range(1, $n) as $i) {
            $user = makeStudent(['collage_name' => "College $n $i"]);
            makeBill($user, 70);
            App\Models\StudentAttendance::create(['student_id' => $user->id, 'meal_id' => $this->lunch->id, 'amount' => 70, 'date' => '2026-11-25', 'is_paid' => '0']);
            addExpense($this);
            hire($this);
        }
    };
    $queries = fn () => array_map(function ($url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();

        return count(DB::getQueryLog());
    }, ['/reports', '/reports/summary', '/reports/due', '/reports/meals', '/reports/statements?month=2026-11', '/staff']);
    $add(10);
    $before = $queries();
    $add(90);
    expect($queries())->toBe($before);
});

test('6.14 guest and student', function () {
    foreach (['/reports', '/reports/summary', '/reports/due', '/reports/meals', '/reports/summary/download', '/reports/due/download', '/reports/meals/download'] as $url) {
        $this->actingAs($this->student)->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }
});

test('every number leads to its list', function () {
    addExpense($this);
    foreach ($this->get('/reports/summary')->inertiaProps('months')[0] as $key => $lines) {
        foreach (in_array($key, ['left', 'right']) ? $lines : [] as $line) {
            $this->get($line['href'])->assertOk();
        }
    }
    module($this, 'expenses', false);
    expect(collect($this->get('/reports/summary')->inertiaProps('months')[0]['left'])->firstWhere('key', 'expenses')['href'])->toBeNull();
});

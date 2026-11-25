<?php

use App\Models\ActivityLog;
use App\Models\MenuPlan;
use App\Services\MenuCalendar;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    moneySetup($this);
    $this->dal = dish('Dal');
    $this->rice = dish('Rice');
    $this->kadhi = dish('Kadhi');
});

function shown($test, string $date, $meal = null): ?string
{
    $cell = MenuCalendar::between($date, $date)[$date][($meal ?? $test->lunch)->id];

    return $cell === null ? null : ($cell['closed'] ? 'closed' : implode(',', array_column($cell['items'], 'name')));
}

test('3.1 only one date', function () {
    planMenu($this, [$this->dal, $this->rice])->assertSessionHas('success');
    expect(shown($this, '2026-11-25'))->toBe('Dal,Rice')->and(shown($this, '2026-12-02'))->toBeNull();
});

test('3.2 every Monday', function () {
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => [1]]);
    expect(shown($this, '2026-11-30'))->toBe('Dal')->and(shown($this, '2026-12-07'))->toBe('Dal');
    expect(shown($this, '2026-11-23'))->toBeNull()->and(shown($this, '2026-12-01'))->toBeNull();
});

test('3.3 chosen days and every day', function () {
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => [1, 3]]);
    $week = array_map(fn ($day) => shown($this, "2026-12-0$day"), range(1, 7));
    expect($week)->toBe([null, 'Dal', null, null, null, null, 'Dal']);
    planMenu($this, [$this->rice], ['repeat' => 'weekly', 'weekdays' => range(1, 7)]);
    expect(array_unique(array_map(fn ($day) => shown($this, "2026-12-0$day"), range(1, 7))))->toBe(['Rice'])->and(MenuPlan::count())->toBe(9);
});

test('3.4 a date wins over a repeat', function () {
    planMenu($this, [$this->rice], ['from' => '2026-11-30']);
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => [1]]);
    expect(shown($this, '2026-11-30'))->toBe('Rice')->and(shown($this, '2026-12-07'))->toBe('Dal');
});

test('3.5 a temporary repeat ends', function () {
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => [1]]);
    planMenu($this, [$this->rice], ['repeat' => 'weekly', 'weekdays' => [1], 'until' => '2026-12-07']);
    expect(shown($this, '2026-12-07'))->toBe('Rice')->and(shown($this, '2026-12-14'))->toBe('Dal');
});

test('3.6 until', function () {
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => [1], 'until' => '2026-11-30']);
    expect(shown($this, '2026-11-30'))->toBe('Dal')->and(shown($this, '2026-12-07'))->toBeNull();
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => [1], 'until' => '2026-11-28'])->assertSessionHasErrors('weekdays');
});

test('3.7 meals are independent', function () {
    planMenu($this, [$this->dal]);
    planMenu($this, [$this->rice], ['meal_id' => $this->dinner->id]);
    expect(shown($this, '2026-11-25'))->toBe('Dal')->and(shown($this, '2026-11-25', $this->dinner))->toBe('Rice');
});

test('3.8 bad input', function ($data) {
    $data = value($data);
    planMenu($this, [$this->dal], $data)->assertSessionHasErrors();
    expect(MenuPlan::count())->toBe(0)->and(ActivityLog::count())->toBe(0);
})->with([
    'yesterday' => [['from' => '2026-11-24']], 'bad date' => [['from' => 'x']],
    'until before' => [['repeat' => 'weekly', 'weekdays' => [1], 'until' => '2026-11-24']],
    '367 days' => [['repeat' => 'weekly', 'weekdays' => [1], 'until' => '2027-11-27']],
    'day 0' => [['repeat' => 'weekly', 'weekdays' => [0]]], 'day 8' => [['repeat' => 'weekly', 'weekdays' => [8]]],
    'no days' => [['repeat' => 'weekly', 'weekdays' => []]], 'same day twice' => [['repeat' => 'weekly', 'weekdays' => [1, 1]]],
    'repeat' => [['repeat' => 'yearly']], 'no items' => [['items' => []]], 'items text' => [['items' => 'x']],
    'nested' => [['items' => [[1]]]], 'unknown' => [['items' => [999]]],
    'repeated' => [fn () => ['items' => [test()->dal->id, test()->dal->id]]],
    '31' => [fn () => ['items' => array_map(fn ($n) => dish("D$n")->id, range(1, 31))]],
    'unavailable' => [fn () => ['items' => [dish('Off', ['available' => false])->id]]],
    'note' => [['note' => str_repeat('a', 101)]], 'meal' => [['meal_id' => 999]],
]);

test('3.8 a meal that is off', function () {
    $this->dinner->update(['active' => false]);
    planMenu($this, [$this->dal], ['meal_id' => $this->dinner->id])->assertSessionHas('error');
    expect(MenuPlan::count())->toBe(0);
});

test('3.9 stop', function () {
    planMenu($this, [$this->dal, $this->rice], ['repeat' => 'weekly', 'weekdays' => [1]]);
    planMenu($this, [$this->kadhi], ['repeat' => 'weekly', 'weekdays' => [1]]);
    $edit = "/menu-plan/edit?meal={$this->lunch->id}&date=2026-12-07";
    $this->get($edit)->assertInertia(fn (Assert $page) => $page->component('MenuPlan/Form')->where('plan.instead', 'Dal, Rice'));
    $this->travelTo('2026-12-02 10:00:00');
    $this->post('/menu-plan/2/stop')->assertSessionHas('success');
    expect(MenuPlan::find(2)->ends_on)->toBe('2026-12-01');
    expect(shown($this, '2026-12-07'))->toBe('Dal,Rice')->and(shown($this, '2026-11-30'))->toBe('Kadhi');
    $this->post('/menu-plan/2/stop')->assertSessionHas('error');

    planMenu($this, [$this->kadhi], ['from' => '2026-12-07']);
    $this->post('/menu-plan/3/stop');
    expect(shown($this, '2026-12-07'))->toBe('Dal,Rice');
    planMenu($this, [$this->kadhi], ['from' => '2026-12-20', 'repeat' => 'weekly', 'weekdays' => [1]]);
    $this->post('/menu-plan/4/stop');
    expect(MenuPlan::whereKey([3, 4])->count())->toBe(0);
    $this->get("$edit&x=1")->assertInertia(fn (Assert $page) => $page->where('plan.instead', null));

    planMenu($this, [$this->kadhi]);
    $id = MenuPlan::max('id');
    $this->travelTo('2026-12-03 10:00:00');
    $this->post("/menu-plan/$id/stop")->assertSessionHas('error');
    expect(MenuPlan::whereKey($id)->exists())->toBeTrue();
    $this->post('/menu-plan/999/stop')->assertNotFound();
});

test('3.10 changing the weekly menu keeps the past', function () {
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => range(1, 7)]);
    $this->travelTo('2026-12-10 10:00:00');
    planMenu($this, [$this->rice], ['repeat' => 'weekly', 'weekdays' => range(1, 7)]);
    expect(shown($this, '2026-12-09'))->toBe('Dal')->and(shown($this, '2026-12-10'))->toBe('Rice');
});

test('3.11 no meal', function () {
    planMenu($this, [], ['closed' => true, 'note' => 'Holiday']);
    planMenu($this, [$this->dal], ['closed' => true, 'from' => '2026-11-26']);
    expect(shown($this, '2026-11-25'))->toBe('closed')->and(shown($this, '2026-11-26'))->toBe('closed');
    expect(DB::table('menu_plan_items')->count())->toBe(0)->and(MenuPlan::find(1)->note)->toBe('Holiday');
});

test('3.12 the same date twice', function () {
    planMenu($this, [$this->dal]);
    planMenu($this, [$this->rice]);
    expect(MenuPlan::count())->toBe(1)->and(shown($this, '2026-11-25'))->toBe('Rice');
});

test('3.13 copy a week', function () {
    $off = dish('Off');
    $this->travelTo('2026-11-23 10:00:00');
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => [1, 2]]);
    planMenu($this, [$this->rice, $off], ['from' => '2026-11-25']);
    planMenu($this, [], ['from' => '2026-11-26', 'closed' => true, 'note' => 'Holiday']);
    planMenu($this, [$off], ['from' => '2026-11-27']);
    planMenu($this, [$this->kadhi], ['from' => '2026-12-01']);
    $off->update(['available' => false]);
    $this->dinner->update(['active' => false]);

    $this->post('/menu-plan/copy', ['week' => '2026-11-30'])->assertSessionHas('success', '3 copied, 2 skipped.');
    expect([shown($this, '2026-11-30'), shown($this, '2026-12-01'), shown($this, '2026-12-02'), shown($this, '2026-12-03'), shown($this, '2026-12-04')])
        ->toBe(['Dal', 'Kadhi', 'Rice', 'closed', null]);
    $this->post('/menu-plan/copy', ['week' => '2026-11-30'])->assertSessionHas('success', '0 copied, 5 skipped.');
    $this->post('/menu-plan/copy', ['week' => ['x']])->assertSessionHasErrors('week');

    // Days before today are never written.
    $this->travelTo('2026-12-09 10:00:00');
    MenuPlan::query()->whereNull('weekday')->where('starts_on', '>=', '2026-12-07')->delete();
    $this->post('/menu-plan/copy', ['week' => '2026-12-07']);
    expect(MenuPlan::where('starts_on', '2026-12-07')->exists())->toBeFalse()->and(MenuPlan::where('starts_on', '2026-12-09')->exists())->toBeTrue();
});

test('3.14 weeks over month, year and leap day', function () {
    planMenu($this, [$this->dal], ['repeat' => 'weekly', 'weekdays' => range(1, 7)]);
    expect(array_keys(MenuCalendar::between('2026-12-28', '2027-01-03')))->toBe(['2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03']);
    expect(shown($this, '2028-02-29'))->toBe('Dal');
    expect(array_keys(MenuCalendar::between('2028-02-28', '2028-03-01')))->toBe(['2028-02-28', '2028-02-29', '2028-03-01']);
});

test('3.15 week in the address', function (string $query, string $monday) {
    $this->get("/menu-plan?$query")->assertInertia(fn (Assert $page) => $page->component('MenuPlan/Index')->where('week', $monday)->has('menus', 7));
})->with([['', '2026-11-23'], ['week=bad', '2026-11-23'], ['week[]=2026-12-07', '2026-11-23'], ['week=2026-12-10', '2026-12-07']]);

test('3.15 the form opens only for an active meal and a coming date', function () {
    $this->get("/menu-plan/edit?meal={$this->lunch->id}&date=2026-11-25")->assertOk();
    foreach (["meal={$this->lunch->id}&date=2026-11-24", "meal={$this->lunch->id}&date=x", 'meal=999&date=2026-11-25', 'meal[]=1&date[]=1'] as $query) {
        $this->get("/menu-plan/edit?$query")->assertRedirect('/menu-plan');
    }
});

test('3.16 a fixed number of queries', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        MenuCalendar::between('2026-11-23', '2026-11-29');

        return count(DB::getQueryLog());
    };
    $this->dal->update(['food_type_id' => optionId('Veg'), 'category_id' => optionId('Dal')]);
    planMenu($this, [$this->dal]);
    $one = $count();
    foreach (range(1, 4) as $n) {
        planMenu($this, [$this->dal, $this->rice], ['repeat' => 'weekly', 'weekdays' => [3, 4, 5, 6, 7]]);
    }
    expect($count())->toBe($one);
});

test('3.17 an error saves nothing', function () {
    planMenu($this, [$this->dal]);
    ActivityLog::creating(fn () => throw new Exception('no'));
    planMenu($this, [$this->rice])->assertServerError();
    $this->post('/menu-plan/1/stop')->assertServerError();
    $this->post('/menu-plan/copy', ['week' => '2026-11-30'])->assertServerError();
    expect(MenuPlan::count())->toBe(1)->and(shown($this, '2026-11-25'))->toBe('Dal');
});

test('3.18 guest and student', function () {
    $routes = [['get', '/menu-plan'], ['get', '/menu-plan/edit'], ['post', '/menu-plan'], ['post', '/menu-plan/copy'], ['post', '/menu-plan/1/stop']];
    $this->actingAs($this->student);
    foreach ($routes as [$method, $url]) {
        $this->$method($url)->assertForbidden();
    }
    auth()->logout();
    foreach ($routes as [$method, $url]) {
        $this->$method($url)->assertRedirect('/login');
    }
});

test('plans are listed under Menu in the changes', function () {
    planMenu($this, [$this->dal]);
    $this->get('/changes?type=menu')->assertInertia(fn (Assert $page) => $page->has('changes.data', 1));
});

<?php

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Expense;
use App\Models\StaffEntry;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00');
    $this->actingAs(makeAdmin());
});

function moduleRoutes(string $module): array
{
    return collect(app('router')->getRoutes())->filter(fn ($route) => in_array("module:$module", $route->gatherMiddleware(), true))
        ->map(fn ($route) => [$route->methods()[0], '/' . preg_replace('/\{[^}]+\}/', '1', $route->uri())])->values()->all();
}

test('1.1 after the migration', function () {
    expect(AppSetting::isOn('expenses'))->toBeTrue()->and(AppSetting::isOn('staff'))->toBeFalse()->and(AppSetting::isOn('cash'))->toBeFalse();
    AppSetting::query()->delete();
    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('modules', ['expenses' => true, 'staff' => false, 'cash' => false]));
});

test('1.2 each module off', function (string $module, int $count) {
    module($this, 'staff');
    module($this, 'cash');
    module($this, $module, false)->assertSessionHasNoErrors();
    $routes = moduleRoutes($module);
    expect(count($routes))->toBe($count);
    foreach ($routes as [$method, $url]) {
        $this->call($method, $url)->assertNotFound();
    }
    $this->get('/settings')->assertInertia(fn ($page) => $page->where("modules.$module", false));
})->with([['expenses', 5], ['staff', 10], ['cash', 3]]);

test('1.3 off and on again', function () {
    module($this, 'staff');
    $staff = hire($this);
    staffEntry($this, $staff, 'payment', '2026-11', '4000')->assertSessionHasNoErrors();
    addExpense($this);
    module($this, 'staff', false);
    module($this, 'expenses', false);
    expect(StaffEntry::count())->toBe(1)->and(Expense::count())->toBe(1);
    $left = collect($this->get('/reports/summary')->inertiaProps('months')[0]['left'])->pluck('amount', 'key');
    expect($left['salaries'])->toBe(-400000)->and($left['expenses'])->toBe(-10000);
    module($this, 'staff');
    $this->get("/staff/{$staff->id}")->assertInertia(fn ($page) => $page->has('entries', 1));
});

test('1.4 bad values', function ($module, $on) {
    module($this, $module, $on)->assertSessionHasErrors();
    expect(ActivityLog::count())->toBe(0);
})->with([['menu', true], ['staff', 'x'], ['staff', [1]], ['staff', null]]);

test('1.4 a list as module', fn () => $this->put('/settings/modules', ['module' => ['staff'], 'on' => true])->assertSessionHasErrors('module'));

test('1.5 the same value again', function () {
    module($this, 'expenses');
    expect(ActivityLog::count())->toBe(0);
    module($this, 'expenses', false);
    module($this, 'expenses', false);
    expect(ActivityLog::sole()->only('action', 'summary'))->toBe(['action' => 'settings.changed', 'summary' => 'Expenses turned off']);
});

test('1.6 guest and student', function () {
    foreach ([false, true] as $on) {
        $this->actingAs(makeAdmin());
        foreach (['expenses', 'staff', 'cash'] as $module) {
            module($this, $module, $on);
        }
        foreach ([...moduleRoutes('expenses'), ...moduleRoutes('staff'), ...moduleRoutes('cash'), ['PUT', '/settings/modules'], ['GET', '/reports']] as [$method, $url]) {
            auth()->logout();
            $this->call($method, $url)->assertRedirect('/login');
            $this->actingAs(makeStudent())->call($method, $url)->assertForbidden();
        }
    }
});

test('1.7 options', function () {
    module($this, 'staff');
    addExpense($this);
    hire($this);
    $rent = optionId('Rent');
    $this->delete("/settings/options/$rent")->assertSessionHas('error', 'Rent is used by 1 expense. Turn it off instead.');
    $this->delete('/settings/options/' . optionId('Cook'))->assertSessionHas('error', 'Cook is used by 1 staff record. Turn it off instead.');
    $this->delete('/settings/options/' . optionId('Electricity'))->assertSessionHas('success');
    $this->put("/settings/options/$rent", ['active' => false]);
    $this->get('/expenses/create')->assertInertia(fn ($page) => $page->where('types', fn ($types) => $types->pluck('name')->all() === ['Gas', 'Repairs', 'Other']));
    $this->get('/settings')->assertInertia(fn ($page) => $page->has('kinds.expense_type', 4)->has('kinds.staff_job', 4));
});

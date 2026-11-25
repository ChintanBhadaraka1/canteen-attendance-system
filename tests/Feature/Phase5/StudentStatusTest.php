<?php

use App\Models\ActivityLog;
use App\Models\StudentAttendance;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

function status($test, $value, ?User $student = null)
{
    return $test->patch('/user/' . ($student ?? $test->student)->id . '/status', ['status' => $value]);
}

test('6.1 left and back', function () {
    status($this, 'left')->assertRedirect("/user/{$this->student->id}")->assertSessionHas('success', 'Asha Patel marked as left.');
    expect($this->student->fresh()->left_on)->toBe(today()->toDateString());
    status($this, 'active')->assertSessionHas('success', 'Asha Patel marked as active.');
    expect($this->student->fresh()->left_on)->toBeNull();
    expect(ActivityLog::pluck('action')->all())->toBe(['student.left', 'student.returned']);
});

test('6.2 a student who left cannot be marked', function () {
    status($this, 'left');
    $this->get('/student-attendance')->assertInertia(fn (Assert $page) => $page->has('students.data', 0));
    $this->get('/student-attendance/bulk')->assertInertia(fn (Assert $page) => $page->has('students', 0));
    markMeal($this, $this->lunch)->assertSessionHas('error', 'Asha Patel has left, so attendance cannot be added.');
    $this->post('/student-attendance/quick', ['canteen_id' => 'C1', 'meal_id' => $this->lunch->id])->assertSessionHasErrors('canteen_id');
    $this->get("/student-attendance/show/{$this->student->id}")->assertInertia(fn (Assert $page) => $page->where('canMark', false));

    expect(StudentAttendance::count())->toBe(0);
});

test('6.3 the bill stays', function () {
    markMeal($this, $this->lunch);
    markMeal($this, $this->dinner);
    status($this, 'left');
    $this->get('/bill')->assertInertia(fn (Assert $page) => $page->has('bills.data', 1)->where('bills.data.0.left', true));
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('students', 0)->where('due.total', 170));
    payBillOf($this, 170)->assertSessionHas('success');
    $this->get("/student-attendance/show/{$this->student->id}")->assertOk();
    $this->get("/user/{$this->student->id}")->assertInertia(fn (Assert $page) => $page->where('student.left_on', today()->toDateString()));
});

test('6.4 filters', function () {
    foreach ([['Aa', 'North', null], ['Bb', 'North', '2026-01-01'], ['Cc', 'South', null], ['Dd', 'South', '2026-01-01']] as [$name, $college, $left]) {
        makeStudent(['name' => $name, 'collage_name' => $college, 'left_on' => $left]);
    }
    $this->student->forceFill(['collage_name' => 'East'])->save();
    $names = function (string $query): array {
        $rows = [];
        $this->get("/user?$query")->assertOk()->assertInertia(function (Assert $page) use (&$rows) {
            $rows = array_column($page->toArray()['props']['students']['data'], 'name');
        });

        return $rows;
    };

    expect($names(''))->toBe(['Aa', 'Asha', 'Cc'])->and($names('status=left'))->toBe(['Bb', 'Dd']);
    expect($names('college=North'))->toBe(['Aa'])->and($names('college=North&status=left'))->toBe(['Bb']);
    expect($names('college=South&search=Cc&page=9'))->toBe(['Cc'])->and($names('college=Nor'))->toHaveCount(3);
    expect($names('college[]=x&status[]=left'))->toHaveCount(3)->and($names('status=x'))->toHaveCount(3);
    $this->get('/user')->assertInertia(fn (Assert $page) => $page->where('colleges', ['East', 'North', 'South']));
});

test('6.6 same status twice and crafted values', function () {
    status($this, 'left');
    $this->travel(2)->days();
    status($this, 'left');
    expect(ActivityLog::count())->toBe(1)->and($this->student->fresh()->left_on)->toBe(today()->subDays(2)->toDateString());
    status($this, 'x')->assertSessionHasErrors('status');
    status($this, ['left'])->assertSessionHasErrors('status');

    $other = makeStudent(['canteen_id' => 'C5', 'phone_number' => '9876543210']);
    $this->put("/user/{$other->id}", ['name' => 'New', 'collage_name' => 'North', 'email' => $other->email, 'canteen_id' => 'C5', 'phone_number' => '9876543210', 'left_on' => '2026-01-01'])->assertSessionHasNoErrors();
    expect($other->fresh())->name->toBe('New')->left_on->toBeNull();
});

test('6.7 access', function () {
    status($this, 'left', $this->admin)->assertNotFound();
    $this->actingAs($this->student);
    status($this, 'left')->assertForbidden();
    auth()->logout();
    status($this, 'left')->assertRedirect('/login');
    expect($this->student->fresh()->left_on)->toBeNull();
});

test('a student who left can still sign in', function () {
    status($this, 'left');
    auth()->logout();
    $this->post('/login', ['email' => $this->student->email, 'password' => 'password'])->assertRedirect('/account');
});

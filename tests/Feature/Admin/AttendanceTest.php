<?php

use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\StudentAttendance;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(makeAdmin());
    $this->lunch = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    $this->student = makeStudent(['name' => 'Asha', 'middle_name' => 'Raj', 'last_name' => 'Patel', 'canteen_id' => 'C1']);
});

function attend($test, array $data = [])
{
    return $test->post('/student-attendance/store', $data + [
        'user_id' => $test->student->id, 'meal_id' => $test->lunch->id, 'include_extra' => 'no',
    ]);
}

test('list shows who is marked today', function () {
    $id = $this->lunch->id;
    attend($this);
    makeStudent(['name' => 'Zed']);

    $this->get("/student-attendance?meal=$id")->assertInertia(fn (Assert $page) => $page
        ->component('Attendance/Index')->where('meal', $id)
        ->where('students.data.0.marked', true)->where('students.data.1.marked', false));

    foreach (['', '?meal=999', '?meal=abc', '?meal[]=1'] as $query) {
        $this->get("/student-attendance$query")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('meal', null)->where('students.data.0.marked', null));
    }
});

test('yesterday does not count as marked', function () {
    attend($this, ['date' => today()->subDay()->toDateString()]);

    $this->get("/student-attendance?meal={$this->lunch->id}")
        ->assertInertia(fn (Assert $page) => $page->where('students.data.0.marked', false));
});

test('one tap mark returns to the same list', function () {
    attend($this, ['return_to' => 'list', 'search' => 'Asha', 'page' => 1])
        ->assertRedirect(route('student-attendance.index', ['meal' => $this->lunch->id, 'search' => 'Asha', 'page' => 1]))
        ->assertSessionHas('success', 'Lunch marked for Asha Raj Patel.');
    expect(StudentAttendance::sole()->date)->toBe(today()->toDateString());
});

test('return address from the browser is ignored', function () {
    attend($this, ['return_to' => 'https://evil.example'])->assertRedirect(route('student-attendance.index'));
});

test('duplicate is rejected without success', function () {
    StudentAttendance::create(['student_id' => $this->student->id, 'meal_id' => $this->lunch->id, 'amount' => 70, 'date' => today()->toDateString()]);
    makeBill($this->student, 70);

    attend($this, ['return_to' => 'list'])
        ->assertRedirect(route('student-attendance.index', ['meal' => $this->lunch->id]))
        ->assertSessionHas('error', 'Lunch is already marked for this date.')->assertSessionMissing('success');
    expect(StudentAttendance::count())->toBe(1)->and(Payment::sole()->amount)->toBe(70);
});

test('a date before the last payment is rejected', function () {
    attend($this, ['date' => '2026-01-10']);
    StudentAttendance::query()->update(['is_paid' => 1]);

    attend($this, ['date' => '2026-01-09'])->assertSessionHas('error', 'This date is already paid, so attendance cannot be added.');
    expect(StudentAttendance::count())->toBe(1)->and(Payment::sole()->amount)->toBe(70);
});

test('form saves date and extras', function () {
    Menus::create(['name' => 'Curd', 'slug' => 'curd', 'is_extra' => '1', 'price' => 15]);
    Menus::create(['name' => 'Dal', 'slug' => 'dal']);

    $this->get("/student-attendance/create/{$this->student->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Attendance/Create')->where('student.id', $this->student->id)->has('meals', 1)
        ->has('extras', 1)->where('extras.0.slug', 'curd')->where('today', today()->toDateString()));
    $this->get('/student-attendance/create/999')->assertNotFound();

    attend($this, ['date' => '2026-01-10', 'include_extra' => 'yes', 'extra_items' => ['curd' => 2]])->assertRedirect('/student-attendance');
    expect(StudentAttendance::sole())->amount->toEqual(70)->extra_amount->toEqual(30)->date->toBe('2026-01-10');
    expect(Payment::sole()->amount)->toBe(100);
});

test('asking for extras without choosing any is not an error', function () {
    attend($this, ['include_extra' => 'yes'])->assertRedirect('/student-attendance');
    expect(Payment::sole()->amount)->toBe(70);
});

test('history filters by date and meal', function () {
    $tea = MealPrice::create(['name' => 'Tea', 'slug' => 'tea', 'price' => 5]);
    attend($this, ['date' => '2026-01-10']);
    attend($this, ['date' => '2026-01-20', 'meal_id' => $tea->id]);
    StudentAttendance::where('meal_id', $this->lunch->id)->update(['is_paid' => 1]);
    $url = "/student-attendance/show/{$this->student->id}";

    $this->get("$url?from=2026-01-01&to=2026-01-31")->assertInertia(fn (Assert $page) => $page
        ->component('Attendance/Show')->has('rows.data', 2)
        ->where('rows.data.0.meal', 'Tea')->where('rows.data.0.is_paid', false)->where('rows.data.1.is_paid', true));
    $this->get("$url?from=2026-01-15&to=2026-01-31")->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));
    $this->get("$url?from=2026-01-01&to=2026-01-31&search=lun")
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.meal', 'Lunch'));
    $this->get("$url?from=bad&to[]=x")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', today()->subDays(29)->toDateString())->where('filters.to', today()->toDateString()));
});

test('history opens for a removed meal and a deleted student', function () {
    attend($this);
    StudentAttendance::query()->update(['meal_id' => null]);
    $this->student->delete();

    $this->get("/student-attendance/show/{$this->student->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.data.0.meal', 'Removed meal'));
    $this->get('/student-attendance/show/999')->assertNotFound();
});

test('attendance is deleted', function () {
    attend($this, ['date' => '2026-01-10']);

    $this->post('/student-attendance/delete', ['id' => StudentAttendance::sole()->id, 'from' => '2026-01-01', 'to' => '2026-01-31'])
        ->assertRedirect(route('student-attendance.show', ['id' => $this->student->id, 'from' => '2026-01-01', 'to' => '2026-01-31']))
        ->assertSessionHas('success', 'Lunch on 10 January 2026 deleted.');
    expect(StudentAttendance::count())->toBe(0)->and(Payment::sole()->amount)->toBe(0);

    $this->post('/student-attendance/delete', ['id' => 999])->assertRedirect('/student-attendance')->assertSessionHas('error');
});

test('csv downloads', function (string $url) {
    attend($this, ['date' => '2026-01-10']);
    $query = http_build_query(['from' => '2026-01-01', 'to' => '2026-01-31', 'user_id' => $this->student->id]);
    $response = $this->get("$url?$query")->assertOk();

    expect($response->streamedContent())
        ->toContain('"Sr No.","Full Name","Canteen ID",Date,"Meal Name",Amount,"Extra Items","Extra Amount"')
        ->toContain('1,"Asha Raj Patel",C1,2026-01-10,Lunch,70.00,None,0.00');
    $this->get($url)->assertSessionHasErrors(['from', 'to']);
})->with(['/student-attendance/download', '/student-attendance/user/download']);

test('the list does not query per student', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get("/student-attendance?meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->has('students.data.0.marked'));

        return count(DB::getQueryLog());
    };
    $one = $count();
    foreach (range(1, 9) as $n) {
        makeStudent();
    }

    expect($count())->toBe($one);
});

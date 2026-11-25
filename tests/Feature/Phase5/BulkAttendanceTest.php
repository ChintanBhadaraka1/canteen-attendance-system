<?php

use App\Models\ActivityLog;
use App\Models\AttendanceBatch;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\StudentLeave;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    moneySetup($this);
    $this->ravi = makeStudent(['name' => 'Ravi', 'middle_name' => null, 'last_name' => 'Shah', 'canteen_id' => 'C2']);
    $this->zed = makeStudent(['name' => 'Zed', 'middle_name' => null, 'last_name' => null, 'canteen_id' => 'C3']);
    $this->all = [$this->student->id, $this->ravi->id, $this->zed->id];
});

function bill($student): ?int
{
    return Payment::where('student_id', $student->id)->value('amount');
}

function nothingSaved(): void
{
    expect(StudentAttendance::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and(AttendanceBatch::count())->toBe(0)->and(ActivityLog::count())->toBe(0);
}

test('2.1 three students', function () {
    bulk($this, $this->all)->assertRedirect('/student-attendance/bulk?date=2026-01-10&meal=' . $this->lunch->id)
        ->assertSessionHas('success', 'Lunch marked for 3 students (₹210).');

    expect(StudentAttendance::where('batch_id', AttendanceBatch::sole()->id)->count())->toBe(3);
    expect(Payment::pluck('amount')->all())->toBe([70, 70, 70]);
    expect(AttendanceBatch::sole())->count->toBe(3)->meal_name->toBe('Lunch')->date->toBe('2026-01-10')->user_name->toBe($this->admin->full_name);
    expect(ActivityLog::sole())->action->toBe('attendance.bulk')->summary->toBe('Lunch on 10 January 2026 marked for 3 students (₹210)')
        ->details->toBe(['batch_id' => AttendanceBatch::sole()->id, 'attendance_ids' => StudentAttendance::pluck('id')->all()]);
});

test('2.2 one already marked', function () {
    markMeal($this, $this->lunch);
    bulk($this, $this->all)->assertSessionHas('bulk', fn ($bulk) => $bulk['skipped'] === [
        ['id' => $this->student->id, 'name' => 'Asha Patel', 'reason' => 'Lunch is already marked for this date.'],
    ]);

    expect(AttendanceBatch::sole()->count)->toBe(2)->and(bill($this->student))->toBe(70)->and(bill($this->ravi))->toBe(70);
});

test('2.3 2.6 all already marked', function () {
    bulk($this, $this->all);
    bulk($this, $this->all)->assertSessionHas('error', 'Nobody was marked.');

    expect(AttendanceBatch::count())->toBe(1)->and(ActivityLog::count())->toBe(1);
    expect(Payment::pluck('amount')->all())->toBe([70, 70, 70]);
});

test('2.4 date before the last paid date', function () {
    markMeal($this, $this->lunch, ['date' => '2026-01-15']);
    payBillOf($this, 70);
    bulk($this, $this->all)->assertSessionHas('bulk', fn ($bulk) => array_column($bulk['skipped'], 'reason', 'name') === [
        'Asha Patel' => 'This date is already paid, so attendance cannot be added.',
    ]);

    expect(StudentAttendance::where('date', '2026-01-10')->count())->toBe(2);
});

test('2.5 deleted or left after the page opened', function () {
    $this->ravi->delete();
    $this->zed->forceFill(['left_on' => '2026-01-01'])->save();
    bulk($this, $this->all)->assertSessionHas('bulk', fn ($bulk) => array_column($bulk['skipped'], 'reason', 'name') === [
        'Ravi Shah' => 'This student is no longer in the list.',
        'Zed' => 'Zed has left, so attendance cannot be added.',
    ]);

    expect(StudentAttendance::count())->toBe(1);
});

test('2.7 the price changed', function () {
    $this->lunch->update(['price' => 90]);
    bulk($this, $this->all)->assertSessionHas('error', 'The price of Lunch is now ₹90. Please check the total and save again.')
        ->assertSessionMissing('bulk');
    nothingSaved();
});

test('2.8 bad requests save nothing', function (Closure $data) {
    bulk($this, $this->all, $data($this))->assertSessionHasErrors();
    nothingSaved();
})->with([
    'empty' => [fn () => ['students' => []]],
    '501' => [fn () => ['students' => range(1, 501)]],
    'repeated' => [fn ($t) => ['students' => [$t->ravi->id, $t->ravi->id]]],
    'text' => [fn () => ['students' => ['x']]],
    'nested' => [fn () => ['students' => [[1]]]],
    'not a list' => [fn () => ['students' => 'x']],
    'admin' => [fn ($t) => ['students' => [$t->admin->id, $t->ravi->id]]],
    'unknown' => [fn () => ['students' => [9999]]],
    'future' => [fn () => ['date' => today()->addDay()->toDateString()]],
    'bad date' => [fn () => ['date' => ['x']]],
    'no date' => [fn () => ['date' => null]],
    'meal' => [fn () => ['meal_id' => 999]],
    'price' => [fn () => ['price' => 'x']],
]);

test('2.8 the limit has a clear message', function () {
    bulk($this, range(1, 501))->assertSessionHasErrors(['students' => 'Please save at most 500 students at a time.']);
});

test('2.9 a failure halfway saves nothing', function () {
    $n = 0;
    StudentAttendance::creating(function () use (&$n) {
        if (++$n === 2) {
            throw new RuntimeException('SECRET');
        }
    });
    bulk($this, $this->all)->assertStatus(500);
    nothingSaved();
});

test('2.9 a failure in the change record saves nothing', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('SECRET'));
    bulk($this, $this->all)->assertStatus(500);
    nothingSaved();
});

test('2.10 advance and pending', function () {
    makeBill($this->ravi, 0, 0, 30);
    makeBill($this->zed, 0, 50);
    bulk($this, $this->all);

    expect(Payment::where('student_id', $this->ravi->id)->sole()->balance)->toBe(40);
    expect(Payment::where('student_id', $this->zed->id)->sole()->balance)->toBe(120);
});

test('2.11 a past date uses the price of today', function () {
    $this->lunch->update(['price' => 90]);
    bulk($this, $this->all, ['date' => '2025-12-01', 'price' => 90]);

    expect(StudentAttendance::where('date', '2025-12-01')->where('amount', '90')->count())->toBe(3);
});

test('2.12 page rows and flags', function () {
    makeStudent(['left_on' => '2026-01-01']);
    makeStudent()->delete();
    markMeal($this, $this->lunch);
    markMeal($this, $this->dinner, ['user_id' => $this->ravi->id, 'date' => '2026-01-15']);
    $bill = Payment::where('student_id', $this->ravi->id)->sole();
    $this->put("/bill/{$bill->id}", ['amount' => 100, 'type' => 'cash', 'month' => 'January', 'due' => 100]);
    StudentLeave::create(['student_id' => $this->zed->id, 'from_date' => '2026-01-09', 'to_date' => '2026-01-12']);

    $this->get("/student-attendance/bulk?date=2026-01-10&meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Attendance/Bulk')->has('students', 3)->where('date', '2026-01-10')->where('meal', $this->lunch->id)
        ->where('students.0', fn ($row) => [$row['name'], $row['marked'], $row['paid_block'], $row['away']] === ['Asha', true, false, null])
        ->where('students.1', fn ($row) => [$row['name'], $row['marked'], $row['paid_block'], $row['away']] === ['Ravi', false, true, null])
        ->where('students.2', fn ($row) => [$row['name'], $row['marked'], $row['paid_block'], $row['away']] === ['Zed', false, false, '2026-01-12'])
        ->missing('students.0.email'));
});

test('2.13 same as last time', function () {
    $last = function (string $date) {
        $ids = null;
        $this->get("/student-attendance/bulk?date=$date&meal={$this->lunch->id}")->assertInertia(function (Assert $page) use (&$ids) {
            $ids = $page->toArray()['props']['last'];
        });

        return $ids;
    };
    expect($last('2026-01-10'))->toBe([]);
    bulk($this, [$this->zed->id], ['date' => '2026-01-07']);
    bulk($this, [$this->student->id, $this->ravi->id], ['date' => '2026-01-08']);
    bulk($this, [$this->zed->id], ['date' => '2026-01-12']);

    expect($last('2026-01-10'))->toBe([$this->student->id, $this->ravi->id]);
});

test('2.14 the number of queries does not grow', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get("/student-attendance/bulk?date=2026-01-10&meal={$this->lunch->id}")->assertOk();

        return count(DB::getQueryLog());
    };
    User::whereKeyNot([$this->student->id, $this->admin->id])->forceDelete();
    bulk($this, [$this->student->id]);
    $one = $count();
    foreach (range(1, 49) as $n) {
        $ids[] = makeStudent(['name' => "S$n"])->id;
    }
    foreach (array_chunk($ids, 10) as $chunk) {
        bulk($this, $chunk);
    }

    expect($count())->toBe($one);
});

test('2.15 admins only', function () {
    $this->actingAs($this->ravi);
    $this->get('/student-attendance/bulk')->assertForbidden();
    bulk($this, $this->all)->assertForbidden();
    auth()->logout();
    $this->get('/student-attendance/bulk')->assertRedirect('/login');
    bulk($this, $this->all)->assertRedirect('/login');
    nothingSaved();
});

test('2.16 nothing to show', function () {
    User::where('role', 'user')->delete();
    $this->get("/student-attendance/bulk?meal={$this->lunch->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('students', 0));
    MealPrice::query()->delete();
    $this->get('/student-attendance/bulk?meal[]=x&date[]=x')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('meals', 0)->where('meal', null)->where('date', today()->toDateString()));
    $this->get('/student-attendance/bulk?date=2999-01-01')->assertInertia(fn (Assert $page) => $page->where('date', today()->toDateString()));
});

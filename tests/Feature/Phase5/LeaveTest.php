<?php

use App\Models\ActivityLog;
use App\Models\StudentAttendance;
use App\Models\StudentLeave;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

function leave($test, string $from, string $to, ?User $student = null, array $data = [])
{
    return $test->post('/user/' . ($student ?? $test->student)->id . '/leave', $data + ['from_date' => $from, 'to_date' => $to]);
}

test('7.1 add and delete', function () {
    leave($this, '2026-01-10', '2026-01-12', null, ['reason' => 'private illness'])->assertRedirect("/user/{$this->student->id}")->assertSessionHas('success');
    expect(StudentLeave::sole())->from_date->toBe('2026-01-10')->reason->toBe('private illness');
    $this->get("/user/{$this->student->id}")->assertInertia(fn (Assert $page) => $page->has('leaves', 1)->where('leaves.0.reason', 'private illness'));
    $this->delete('/user/leave/' . StudentLeave::sole()->id)->assertRedirect("/user/{$this->student->id}");

    expect(StudentLeave::count())->toBe(0);
    expect(ActivityLog::pluck('summary', 'action')->all())->toBe([
        'student.leave_added' => 'Asha Patel away from 10 January 2026 to 12 January 2026',
        'student.leave_deleted' => 'Leave of Asha Patel from 10 January 2026 to 12 January 2026 deleted',
    ]);
    expect(ActivityLog::all()->toJson())->not->toContain('illness');
});

test('7.2 dates', function () {
    $errors = fn ($from, $to, $student = null) => leave($this, $from, $to, $student)->assertSessionHasErrors();
    $saved = fn ($from, $to, $student = null) => leave($this, $from, $to, $student)->assertSessionHasNoErrors();
    $errors('2026-01-12', '2026-01-10');
    $errors('2026-01-01', '2027-01-02');
    $saved('2026-01-10', '2026-01-10');
    $saved('2026-01-11', '2026-01-15');
    $errors('2026-01-15', '2026-01-20');
    $errors('2026-01-01', '2026-01-31');
    $saved('2026-01-10', '2026-01-15', makeStudent());
    $saved('2027-01-01', '2028-01-01');
    $errors('x', '2026-01-10');
    leave($this, '2026-05-01', '2026-05-02', null, ['reason' => str_repeat('a', 101)])->assertSessionHasErrors('reason');
    leave($this, '2026-05-01', '2026-05-02', null, ['from_date' => ['x']])->assertSessionHasErrors('from_date');

    expect(StudentLeave::count())->toBe(4);
});

test('7.3 7.4 bulk page and saving', function () {
    leave($this, '2026-01-10', '2026-01-12');
    foreach (['2026-01-10' => '2026-01-12', '2026-01-12' => '2026-01-12', '2026-01-13' => null] as $date => $away) {
        $this->get("/student-attendance/bulk?date=$date&meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->where('students.0.away', $away));
    }
    bulk($this, [$this->student->id])->assertSessionHas('success');

    expect(StudentAttendance::count())->toBe(1);
});

test('7.5 only that row, admins only', function () {
    leave($this, '2026-01-10', '2026-01-12');
    $other = makeStudent();
    leave($this, '2026-01-10', '2026-01-12', $other);
    $this->delete('/user/leave/' . StudentLeave::where('student_id', $other->id)->value('id'));
    expect(StudentLeave::sole()->student_id)->toBe($this->student->id);
    $this->delete('/user/leave/999')->assertNotFound();
    leave($this, '2026-02-10', '2026-02-12', $this->admin)->assertNotFound();

    $id = StudentLeave::sole()->id;
    $this->actingAs($other);
    $this->delete("/user/leave/$id")->assertForbidden();
    leave($this, '2026-02-10', '2026-02-12')->assertForbidden();
    auth()->logout();
    $this->delete("/user/leave/$id")->assertRedirect('/login');
    expect(StudentLeave::count())->toBe(1);
});

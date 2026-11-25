<?php

use App\Exceptions\BillingException;
use App\Models\ActivityLog;
use App\Models\AttendanceBatch;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Services\BillingService;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    moneySetup($this);
    $this->ravi = makeStudent(['name' => 'Ravi', 'middle_name' => null, 'last_name' => 'Shah']);
    $this->zed = makeStudent(['name' => 'Zed', 'middle_name' => null, 'last_name' => null]);
    $this->all = [$this->student->id, $this->ravi->id, $this->zed->id];
    bulk($this, $this->all);
    $this->batch = AttendanceBatch::sole();
});

function undo($test, ?AttendanceBatch $batch = null)
{
    return $test->post('/student-attendance/bulk/' . ($batch ?? $test->batch)->id . '/undo', ['date' => '2026-01-10', 'meal' => $test->lunch->id]);
}

test('3.1 3.2 undo once', function () {
    undo($this)->assertRedirect('/student-attendance/bulk?date=2026-01-10&meal=' . $this->lunch->id)
        ->assertSessionHas('success', 'Lunch on 10 January 2026 undone for 3 students.');
    expect(StudentAttendance::count())->toBe(0)->and((int) Payment::sum('amount'))->toBe(0);
    expect($this->batch->fresh())->undone_at->not->toBeNull()->undone_by->toBe($this->admin->id);
    expect(ActivityLog::latest('id')->first()->action)->toBe('attendance.bulk_undone')->and(ActivityLog::count())->toBe(2);

    undo($this)->assertSessionHas('error', 'This save was already undone.');
    expect((int) Payment::sum('amount'))->toBe(0)->and(ActivityLog::count())->toBe(2);
});

test('3.3 3.4 paid blocks the whole undo until cancelled', function () {
    payBillOf($this, 70);
    undo($this)->assertSessionHas('error', 'Asha Patel has already paid, so this save cannot be undone.');
    expect(StudentAttendance::count())->toBe(3)->and($this->batch->fresh()->undone_at)->toBeNull();
    expect(Payment::pluck('amount')->all())->toBe([0, 70, 70]);

    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    undo($this)->assertSessionHas('success');
    expect(StudentAttendance::count())->toBe(0);
});

test('3.3 many paid students are shortened', function () {
    $ids = array_map(fn ($n) => makeStudent(['name' => "P$n", 'middle_name' => null, 'last_name' => null])->id, range(1, 5));
    bulk($this, $ids);
    StudentAttendance::where('batch_id', AttendanceBatch::max('id'))->update(['is_paid' => '1']);
    undo($this, AttendanceBatch::latest('id')->first())
        ->assertSessionHas('error', 'P1, P2, P3 and 2 more have already paid, so this save cannot be undone.');
});

test('3.5 one entry deleted first', function () {
    $this->post('/student-attendance/delete', ['id' => StudentAttendance::first()->id]);
    undo($this)->assertSessionHas('success');

    expect(StudentAttendance::count())->toBe(0)->and(Payment::pluck('amount')->all())->toBe([0, 0, 0]);
});

test('3.6 a later price does not matter', function () {
    $this->lunch->update(['price' => 90]);
    undo($this);

    expect(Payment::pluck('amount')->all())->toBe([0, 0, 0]);
});

test('3.7 other entries stay', function () {
    bulk($this, $this->all, ['meal_id' => $this->dinner->id, 'price' => 100]);
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    undo($this);

    expect(StudentAttendance::count())->toBe(4)->and(Payment::pluck('amount')->all())->toBe([170, 100, 100]);
});

test('3.8 a failure changes nothing', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('SECRET'));
    undo($this)->assertStatus(500);

    expect(StudentAttendance::count())->toBe(3)->and((int) Payment::sum('amount'))->toBe(210)->and($this->batch->fresh()->undone_at)->toBeNull();
});

test('3.9 refusals and access', function () {
    StudentAttendance::all()->each(fn ($entry) => $this->post('/student-attendance/delete', ['id' => $entry->id]));
    undo($this)->assertSessionHas('error', 'All these entries were already deleted.');
    $this->post('/student-attendance/bulk/999/undo')->assertNotFound();
    $this->actingAs($this->ravi);
    undo($this)->assertForbidden();
    auth()->logout();
    undo($this)->assertRedirect('/login');
});

test('3.10 mark again after undo', function () {
    undo($this);
    bulk($this, $this->all)->assertSessionHas('success');

    expect(AttendanceBatch::count())->toBe(2)->and(Payment::pluck('amount')->all())->toBe([70, 70, 70]);
});

test('recent saves say why they cannot be undone', function () {
    bulk($this, $this->all, ['date' => '2026-01-08']);
    bulk($this, [$this->zed->id], ['date' => '2026-01-11']);
    undo($this, AttendanceBatch::latest('id')->first());
    payBillOf($this, 140);

    $this->get('/student-attendance/bulk')->assertInertia(fn (Assert $page) => $page
        ->has('batches', 3)->where('batches.0.blocked', 'Already undone')->where('batches.1.blocked', 'Some students have already paid')
        ->where('batches.2.blocked', 'Some students have already paid'));
});

test('3.11 random walk keeps every rupee', function () {
    mt_srand(5);
    $billing = app(BillingService::class);
    $day = now()->subYears(2);
    $meals = [$this->lunch, $this->dinner];

    foreach (range(1, 200) as $step) {
        $meal = $meals[mt_rand(0, 1)];
        $student = $this->all[mt_rand(0, 2)];
        $bill = Payment::where('student_id', $student)->sole();
        try {
            match (mt_rand(0, 6)) {
                0, 1 => $billing->markMany(array_slice($this->all, 0, mt_rand(1, 3)), $meal->id, $day->addDay()->toDateString(), (int) $meal->price),
                2 => ($batch = AttendanceBatch::whereNull('undone_at')->inRandomOrder(mt_rand())->first()) && $billing->undoBatch($batch->id),
                3 => $billing->markAttendance($student, $meal->id, $day->addDay()->toDateString()),
                4 => ($row = StudentAttendance::where('is_paid', '0')->inRandomOrder(mt_rand())->first()) && $billing->deleteAttendance($row->id),
                5 => $billing->takePayment($bill->id, mt_rand(1, 300), 'cash', 'January', $bill->balance),
                6 => ($row = PaymentHistory::active()->latest('id')->first()) && $billing->cancelPayment($row->id, 'random walk'),
            };
        } catch (BillingException) {
            // A refusal must leave the sums right too.
        }
        foreach ($this->all as $id) {
            $rows = StudentAttendance::where('student_id', $id)->get();
            expect(Payment::where('student_id', $id)->sole()->balance)
                ->toBe((int) $rows->sum('amount') - (int) PaymentHistory::active()->where('student_id', $id)->sum('amount'));
        }
    }
    expect(AttendanceBatch::whereNotNull('undone_at')->count())->toBeGreaterThan(5);
    expect(Artisan::call('canteen:check-money'))->toBe(0);
});

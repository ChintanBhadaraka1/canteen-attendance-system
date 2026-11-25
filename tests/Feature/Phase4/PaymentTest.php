<?php

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    moneySetup($this);
    markMeal($this, $this->lunch);
    markMeal($this, $this->dinner);
});

function untouched(): void
{
    expect(parts())->toBe([170, 0, 0])->and(PaymentHistory::count())->toBe(0);
    expect(StudentAttendance::where('is_paid', '0')->count())->toBe(2);
}

test('3.1 full, part and advance', function (int $paid, array $left) {
    payBillOf($this, $paid)->assertRedirect('/bill');

    expect(parts())->toBe($left)->and(PaymentHistory::sole()->amount)->toBe($paid);
    expect(PaymentHistory::sole()->created_by)->toBe($this->admin->id);
})->with([[170, [0, 0, 0]], [100, [0, 70, 0]], [200, [0, 0, 30]]]);

test('3.2 bad input changes nothing', function (array $data) {
    payBillOf($this, 100, $data)->assertSessionHasErrors();
    untouched();
})->with([
    [['amount' => 0]], [['amount' => -5]], [['amount' => 1.5]], [['amount' => 'abc']], [['amount' => '']],
    [['amount' => []]], [['amount' => 1000001]], [['type' => 'card']], [['month' => 'Smarch']], [['month' => []]], [['due' => null]],
]);

test('3.3 history is exactly what was paid', function () {
    payBillOf($this, 100);
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    payBillOf($this, 140);

    expect(parts())->toBe([0, 0, 0])->and(PaymentHistory::pluck('amount')->all())->toBe([100, 140]);
});

test('3.4 the same payment twice', function () {
    payBillOf($this, 100);
    payBillOf($this, 100, ['due' => 170])->assertRedirect("/bill/{$this->student->id}/edit")
        ->assertSessionHas('error', 'The bill has changed. Please check the amount and save again.');

    expect(PaymentHistory::count())->toBe(1)->and(parts())->toBe([0, 70, 0]);
});

test('3.5 attendance marked after the page opened', function () {
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    payBillOf($this, 170, ['due' => 170])->assertSessionHas('error');

    expect(parts())->toBe([240, 0, 0])->and(PaymentHistory::count())->toBe(0);
});

test('3.6 every unpaid entry is settled whatever the stored dates', function () {
    payBillOf($this, 170);
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    Payment::query()->update(['start_date' => null, 'end_date' => null]);
    payBillOf($this, 70);

    $entry = StudentAttendance::where('date', '2026-01-11')->sole();
    expect($entry->is_paid)->toBe('1')->and($entry->payment_history_id)->toBe(PaymentHistory::max('id'));
});

test('3.7 only the student of the bill changes', function () {
    $other = makeStudent();
    markMeal($this, $this->lunch, ['user_id' => $other->id]);
    payBillOf($this, 170, ['student_id' => $other->id])->assertSessionHas('success');

    expect(Payment::where('student_id', $other->id)->sole()->amount)->toBe(70);
    expect(StudentAttendance::where('student_id', $other->id)->sole()->is_paid)->toBe('0');
    expect(parts())->toBe([0, 0, 0]);
});

test('3.8 nothing due', function (int $advance) {
    Payment::query()->update(['amount' => 0, 'advance_amount' => $advance]);
    payBillOf($this, 10, ['due' => 170])->assertSessionHas('error', 'Nothing is due for this student.');

    expect(PaymentHistory::count())->toBe(0);
})->with([0, 30]);

test('3.9 a failure halfway saves nothing', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('SECRET'));
    payBillOf($this, 100)->assertStatus(500);
    untouched();
});

test('3.10 advance is used first', function () {
    payBillOf($this, 200);
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    payBillOf($this, 40);

    expect(parts())->toBe([0, 0, 0])->and(PaymentHistory::latest('id')->first()->amount)->toBe(40);
});

test('3.11 a deleted student can still pay', function () {
    $this->student->delete();
    payBillOf($this, 170)->assertSessionHas('success');

    expect(parts())->toBe([0, 0, 0]);
});

test('3.12 unknown bill', function () {
    $this->put('/bill/999', ['amount' => 1, 'type' => 'cash', 'month' => 'January', 'due' => 1])->assertNotFound();
});

test('3.13 amounts of old receipts are not shown', function () {
    payBillOf($this, 100);
    $old = PaymentHistory::create(['student_id' => $this->student->id, 'amount' => 55, 'payment_date' => '2025-01-01', 'type' => 'upi', 'month_name' => 'January']);

    $this->get("/bill/{$this->student->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->has('receipts', 2)->where('receipts.0.amount', 100)
        ->where('receipts.1.id', $old->id)->where('receipts.1.amount', null)->where('receipts.1.cancellable', false));
});

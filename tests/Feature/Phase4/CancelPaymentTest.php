<?php

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Services\BillingService;

beforeEach(function () {
    moneySetup($this);
    markMeal($this, $this->lunch);
    markMeal($this, $this->dinner);
});

function cancel($test, ?PaymentHistory $payment = null, $reason = 'Wrong amount')
{
    $payment ??= PaymentHistory::latest('id')->first();

    return $test->from("/bill/{$test->student->id}/edit")->post("/bill/{$payment->id}/cancel", ['reason' => $reason]);
}

function unpaid(): int
{
    return StudentAttendance::where('is_paid', '0')->whereNull('payment_history_id')->count();
}

test('4.1 cancelling restores the bill', function (int $paid) {
    payBillOf($this, $paid);
    cancel($this)->assertRedirect("/bill/{$this->student->id}/edit")
        ->assertSessionHas('success', "Payment of ₹$paid cancelled. ₹170 is now due.");

    expect(parts())->toBe([170, 0, 0])->and(unpaid())->toBe(2);
    expect(PaymentHistory::sole())->cancelled_at->not->toBeNull()->cancelled_by->toBe($this->admin->id)->cancel_reason->toBe('Wrong amount');
})->with([170, 100, 200]);

test('4.2 newer meals stay on the bill', function () {
    payBillOf($this, 100);
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    cancel($this);

    expect(parts())->toBe([240, 0, 0])->and(unpaid())->toBe(3);
});

test('4.3 4.4 newest first', function () {
    payBillOf($this, 100);
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    payBillOf($this, 140);
    cancel($this, PaymentHistory::first())->assertSessionHasErrors(['reason' => 'Cancel the newer payment first.']);
    expect(parts())->toBe([0, 0, 0]);

    cancel($this);
    expect(parts())->toBe([70, 70, 0]);
    cancel($this, PaymentHistory::first());
    expect(parts())->toBe([240, 0, 0]);
});

test('4.5 advance comes back', function () {
    payBillOf($this, 200);
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    payBillOf($this, 40);
    cancel($this);

    expect(parts())->toBe([70, 0, 30]);
});

test('4.6 cancel twice', function () {
    payBillOf($this, 100);
    cancel($this);
    cancel($this)->assertSessionHasErrors(['reason' => 'This payment was already cancelled.']);

    expect(parts())->toBe([170, 0, 0]);
});

test('4.7 a reason is required', function ($reason) {
    payBillOf($this, 100);
    cancel($this, null, $reason)->assertSessionHasErrors('reason');

    expect(parts())->toBe([0, 70, 0])->and(PaymentHistory::sole()->cancelled_at)->toBeNull();
})->with([[''], ['     '], ['ab'], [str_repeat('a', 201)], [['x']]]);

test('4.8 old and unknown payments', function () {
    payBillOf($this, 100);
    PaymentHistory::query()->update(['before_amount' => null]);
    cancel($this)->assertSessionHasErrors(['reason' => 'This old payment cannot be cancelled.']);
    expect(parts())->toBe([0, 70, 0]);

    $this->post('/bill/999/cancel', ['reason' => 'Wrong amount'])->assertNotFound();
});

test('4.9 a failure halfway changes nothing', function () {
    payBillOf($this, 100);
    ActivityLog::creating(fn () => throw new RuntimeException('SECRET'));
    cancel($this)->assertStatus(500);

    expect(parts())->toBe([0, 70, 0])->and(unpaid())->toBe(0)->and(PaymentHistory::sole()->cancelled_at)->toBeNull();
});

test('4.10 admins only', function () {
    payBillOf($this, 100);
    $this->actingAs(makeStudent());
    cancel($this)->assertForbidden();
    auth()->logout();
    cancel($this)->assertRedirect('/login');

    expect(parts())->toBe([0, 70, 0]);
});

test('4.11 work goes on after cancelling', function () {
    payBillOf($this, 100);
    cancel($this);
    $this->post('/student-attendance/delete', ['id' => StudentAttendance::where('meal_id', $this->lunch->id)->sole()->id])->assertSessionHas('success');
    payBillOf($this, 100)->assertSessionHas('success');

    expect(parts())->toBe([0, 0, 0]);
});

test('4.12 other students are untouched', function () {
    $other = makeStudent();
    markMeal($this, $this->lunch, ['user_id' => $other->id]);
    $bill = Payment::where('student_id', $other->id)->sole();
    $this->put("/bill/{$bill->id}", ['amount' => 50, 'type' => 'upi', 'month' => 'January', 'due' => 70]);
    payBillOf($this, 100);
    cancel($this);

    expect($bill->fresh())->amount->toBe(0)->pending_amount->toBe(20);
    expect(StudentAttendance::where('student_id', $other->id)->sole()->is_paid)->toBe('1');
});

test('4.13 receipt of a cancelled payment', function () {
    payBillOf($this, 100);
    $data = ['user_data' => $this->student, 'attedance_counts' => [], 'payment_month' => 'January', 'payment_date' => '2026-01-10', 'payment_mode' => 'CASH', 'total_amount' => 100, 'receipt_number' => '000001'];
    expect((string) view('Bill.receipt', $data + ['payment' => PaymentHistory::sole()]))->not->toContain('CANCELLED')->toContain('Amount paid');

    cancel($this, null, '<script>alert(1)</script>');
    expect((string) view('Bill.receipt', $data + ['payment' => PaymentHistory::sole()]))
        ->toContain('CANCELLED')->toContain('&lt;script&gt;')->not->toContain('<script>');
    expect($this->get('/bill/' . PaymentHistory::sole()->id . '/receipt')->assertOk()->headers->get('content-type'))->toBe('application/pdf');
});

test('4.14 random walk keeps every rupee', function () {
    mt_srand(4);
    $billing = app(BillingService::class);
    $day = now()->subYears(2);
    $meals = [$this->lunch, $this->dinner];

    foreach (range(1, 300) as $step) {
        try {
            match (mt_rand(0, 4)) {
                0, 1 => $billing->markAttendance($this->student->id, $meals[mt_rand(0, 1)]->id, $day->addDay()->toDateString()),
                2 => ($row = StudentAttendance::where('is_paid', '0')->inRandomOrder(mt_rand())->first()) && $billing->deleteAttendance($row->id),
                3 => $billing->takePayment(Payment::sole()->id, mt_rand(1, 300), 'cash', 'January', Payment::sole()->balance),
                4 => ($row = PaymentHistory::active()->latest('id')->first()) && $billing->cancelPayment($row->id, 'random walk'),
            };
        } catch (App\Exceptions\BillingException) {
            // A refusal must leave the sums right too.
        }
        [$amount, $pending, $advance] = parts();
        $sum = fn ($rows) => (int) $rows->get()->sum(fn ($row) => $row->amount + $row->extra_amount);
        expect($amount + $pending - $advance)->toBe($sum(StudentAttendance::query()) - (int) PaymentHistory::active()->sum('amount'));
        expect($amount)->toBe($sum(StudentAttendance::where('is_paid', '0')));
        expect($pending > 0 && $advance > 0)->toBeFalse();
    }
    expect(PaymentHistory::whereNotNull('cancelled_at')->count())->toBeGreaterThan(10);
});

<?php

use App\Models\AttendanceBatch;
use App\Models\GuestMeal;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Services\BillingService;
use App\Exceptions\BillingException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

function ownAndGuests($test): void
{
    markMeal($test, $test->lunch);
    cardGuest($test, $test->lunch, ['plates' => 2]);
}

function paidLinks(): array
{
    return [StudentAttendance::pluck('is_paid')->all(), GuestMeal::pluck('is_paid')->all(), StudentAttendance::sole()->payment_history_id, GuestMeal::first()->payment_history_id];
}

test('2.1 one payment settles both', function () {
    ownAndGuests($this);
    payBillOf($this, 210);
    $id = PaymentHistory::sole()->id;
    expect(parts())->toBe([0, 0, 0])->and(paidLinks())->toBe([['1'], ['1'], $id, $id]);
});

test('2.2 less and more', function (int $paid, array $left) {
    ownAndGuests($this);
    payBillOf($this, $paid);
    expect(parts())->toBe($left)->and(paidLinks()[1])->toBe(['1']);
})->with([[100, [0, 110, 0]], [300, [0, 0, 90]]]);

test('2.3 cancel the payment', function () {
    ownAndGuests($this);
    payBillOf($this, 210);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    expect(parts())->toBe([210, 0, 0])->and(paidLinks())->toBe([['0'], ['0'], null, null]);
});

test('2.4 a plate after the payment', function () {
    ownAndGuests($this);
    payBillOf($this, 210);
    $breakfast = App\Models\MealPrice::create(['name' => 'Breakfast', 'slug' => 'b', 'price' => 30]);
    cardGuest($this, $breakfast);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    expect(parts())->toBe([240, 0, 0])->and(GuestMeal::pluck('is_paid')->unique()->all())->toBe(['0']);
    $this->artisan('canteen:check-money')->assertExitCode(0);
});

test('2.5 plates also close earlier dates', function () {
    cardGuest($this, $this->lunch);
    payBillOf($this, 70);
    markMeal($this, $this->lunch, ['date' => '2026-01-09'])->assertSessionHas('error', 'This date is already paid, so attendance cannot be added.');
    bulk($this, [$this->student->id], ['date' => '2026-01-09'])->assertSessionHas('error', 'Nobody was marked.');
    cardGuest($this, $this->lunch, ['date' => '2026-01-09'])->assertSessionHasErrors('guest');
    $this->get("/student-attendance/bulk?date=2026-01-09&meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->where('students.0.paid_block', true));
    $this->get("/student-attendance/bulk?date=2026-01-10&meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->where('students.0.paid_block', false));
    expect(StudentAttendance::count())->toBe(0)->and(GuestMeal::count())->toBe(1);
});

test('2.6 the same date stays open', function () {
    cardGuest($this, $this->lunch);
    payBillOf($this, 70);
    markMeal($this, $this->lunch)->assertSessionHas('success');
    expect(parts())->toBe([70, 0, 0]);
});

test('2.5 the later of both dates counts', function () {
    markMeal($this, $this->lunch, ['date' => '2026-01-12']);
    cardGuest($this, $this->lunch);
    payBillOf($this, 140);
    markMeal($this, $this->dinner, ['date' => '2026-01-11'])->assertSessionHas('error');
    $this->get("/student-attendance/bulk?date=2026-01-11&meal={$this->lunch->id}")->assertInertia(fn (Assert $page) => $page->where('students.0.paid_block', true));
});

test('2.7 others are left alone', function () {
    ownAndGuests($this);
    $asha = $this->student;
    $this->student = makeStudent();
    cardGuest($this, $this->lunch);
    walkIn($this, $this->lunch);
    $others = fn () => GuestMeal::where('student_id', '!=', $asha->id)->orWhereNull('student_id')->get()->toArray();
    $before = $others();
    $this->student = $asha;
    payBillOf($this, 210);
    expect($others())->toBe($before);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    expect($others())->toBe($before);
});

test('2.8 a plate after the pay page opened', function () {
    ownAndGuests($this);
    $bill = Payment::sole();
    cardGuest($this, $this->lunch);
    $this->put("/bill/{$bill->id}", ['amount' => 210, 'type' => 'cash', 'month' => 'January', 'due' => 210])->assertSessionHas('error');
    expect(PaymentHistory::count())->toBe(0);
});

test('2.9 undo of a bulk save', function () {
    cardGuest($this, $this->lunch);
    bulk($this, [$this->student->id]);
    expect(Payment::sole()->amount)->toBe(140);
    $this->post('/student-attendance/bulk/' . AttendanceBatch::sole()->id . '/undo')->assertSessionHas('success');
    expect(StudentAttendance::count())->toBe(0)->and(GuestMeal::count())->toBe(1)->and(Payment::sole()->amount)->toBe(70);
});

test('2.10 random walk', function () {
    mt_srand(6);
    $billing = app(BillingService::class);
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    $students = collect([$this->student, makeStudent(), makeStudent()])->pluck('id');
    $date = fn () => '2026-01-' . str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
    $meal = fn () => mt_rand(0, 1) ? $this->lunch : $this->dinner;
    $pick = fn ($query) => $query->inRandomOrder(mt_rand())->value('id') ?? 0;
    $done = [];

    foreach (range(1, 300) as $step) {
        $student = $students[mt_rand(0, 2)];
        $data = guestData($meal(), ['date' => $date(), 'plates' => mt_rand(1, 4), 'extras' => mt_rand(0, 1) ? [$curd->id => mt_rand(1, 3)] : []]);
        $do = ['own', 'own', 'own', 'bulk', 'guest', 'guest', 'guest', 'walk', 'delete', 'undo', 'cancelGuest', 'pay', 'cancelPay'][mt_rand(0, 12)];
        try {
            match ($do) {
                'own' => $billing->markAttendance($student, $meal()->id, $date()),
                'bulk' => $billing->markMany($students->all(), $this->lunch->id, $date(), 70),
                'guest' => $billing->addCardGuest($student, $data),
                'walk' => $billing->addWalkIn($data + ['method' => 'upi']),
                'delete' => $billing->deleteAttendance(StudentAttendance::where('student_id', $student)->value('id') ?? 0),
                'undo' => ($id = AttendanceBatch::whereNull('undone_at')->max('id')) && $billing->undoBatch($id),
                'cancelGuest' => ($id = GuestMeal::latest('id')->skip(mt_rand(0, 3))->value('id')) && $billing->cancelGuest($id, 'Wrong'),
                'pay' => ($bill = Payment::where('student_id', $student)->first()) && $billing->takePayment($bill->id, mt_rand(1, 500), 'cash', 'January', $bill->balance),
                'cancelPay' => ($id = PaymentHistory::active()->where('student_id', $student)->max('id')) && $billing->cancelPayment($id, 'Wrong'),
            };
            $done[$do] = true;
        } catch (BillingException) {
        }

        foreach (Payment::all() as $bill) {
            $own = StudentAttendance::where('student_id', $bill->student_id)->where('is_paid', '0')->sum(Illuminate\Support\Facades\DB::raw('amount + extra_amount'));
            $guest = GuestMeal::where('student_id', $bill->student_id)->where('is_paid', '0')->whereNull('cancelled_at')->sum('amount');
            $all = StudentAttendance::where('student_id', $bill->student_id)->sum(Illuminate\Support\Facades\DB::raw('amount + extra_amount'))
                + GuestMeal::where('student_id', $bill->student_id)->whereNull('cancelled_at')->sum('amount');
            $paid = PaymentHistory::active()->where('student_id', $bill->student_id)->sum('amount');
            if ($bill->amount !== (int) ($own + $guest) || $bill->balance !== (int) ($all - $paid)) {
                $this->fail("step $step ($do): bill {$bill->amount}, rows " . ($own + $guest) . ", balance {$bill->balance}, expected " . ($all - $paid));
            }
        }
    }
    expect(array_keys($done))->toHaveCount(9);
    $this->artisan('canteen:check-money')->assertExitCode(0);
});

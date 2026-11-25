<?php

use App\Models\AttendanceExtra;
use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\StudentStatement;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00');
    moneySetup($this);
    $this->bill = fn (string $month = '2026-11', ?User $student = null) => app(StudentStatement::class)->for([($student ?? $this->student)->id], $month)[0];
});

function bills($test, string $query = 'month=2026-11')
{
    return $test->get("/reports/statements?$query")->assertOk()->inertiaProps();
}

test('5.1 opening, meals, paid', function () {
    markMeal($this, $this->lunch, ['date' => '2026-10-05']);
    Payment::sole()->update(['pending_amount' => -20]);
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    markMeal($this, $this->dinner, ['date' => '2026-11-05']);
    payBillOf($this, 100);
    $bill = ($this->bill)();
    expect($bill)->toMatchArray(['available' => true, 'opening' => 50, 'closing' => 120, 'charged' => 170, 'paid' => 100])
        ->and($bill['meals'])->toBe([['name' => 'Dinner', 'count' => 1, 'amount' => 100], ['name' => 'Lunch', 'count' => 1, 'amount' => 70]])
        ->and($bill['payments'])->toBe([['date' => '2026-11-25', 'method' => 'Cash', 'number' => '000001', 'amount' => 100]]);
    $pdf = $this->get("/bill/statement/{$this->student->id}?month=2026-11")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Content-Disposition'))->toContain('bill_C1_2026-11.pdf')->and($pdf->getContent())->toStartWith('%PDF');
});

test('5.1 card guests, not walk-ins', function () {
    cardGuest($this, $this->lunch, ['date' => '2026-11-05', 'plates' => 2]);
    cardGuest($this, $this->lunch, ['date' => '2026-10-05']);
    walkIn($this, $this->lunch, ['date' => '2026-11-05']);
    cancelGuest($this, GuestMeal::where('date', '2026-11-05')->where('type', 'card')->value('id') ?? 0);
    cardGuest($this, $this->lunch, ['date' => '2026-11-05', 'plates' => 3]);
    expect(($this->bill)())->toMatchArray(['opening' => 70, 'closing' => 280, 'meals' => [['name' => 'Lunch, guest', 'count' => 3, 'amount' => 210]]]);
});

test('5.2 month to month', function () {
    markMeal($this, $this->lunch, ['date' => '2026-09-05']);
    markMeal($this, $this->lunch, ['date' => '2026-10-05']);
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    expect(($this->bill)('2026-09')['closing'])->toBe(($this->bill)('2026-10')['opening'])->toBe(70)
        ->and(($this->bill)('2026-10')['closing'])->toBe(($this->bill)('2026-11')['opening'])
        ->and(($this->bill)('2026-11')['closing'])->toBe(Payment::sole()->balance);
});

test('5.3 advance', function () {
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    payBillOf($this, 100);
    expect(($this->bill)()['closing'])->toBe(-30);
    $html = view('Bill.statement', ['bills' => [($this->bill)()]])->render();
    expect($html)->toContain('Advance')->toContain('₹30')->not->toContain('-30');
});

test('5.4 marked late', function () {
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    markMeal($this, $this->lunch, ['date' => '2026-10-31']);
    expect(($this->bill)('2026-10'))->toMatchArray(['opening' => 0, 'closing' => 70])->and(($this->bill)())->toMatchArray(['opening' => 70, 'closing' => 140]);
});

test('5.5 cancelled and deleted', function () {
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    payBillOf($this, 70);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    $row = markMeal($this, $this->dinner, ['date' => '2026-11-05'], true);
    $this->post('/student-attendance/delete', ['id' => $row->id]);
    expect(StudentAttendance::count())->toBe(1)->and(($this->bill)())->toMatchArray(['payments' => [], 'opening' => 0, 'closing' => 70])->and(count(($this->bill)()['meals']))->toBe(1);
});

test('5.6 saved amounts', function () {
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    $this->lunch->update(['price' => 500]);
    expect(($this->bill)()['meals'][0]['amount'])->toBe(70);
});

test('5.7 extras', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    $row = markMeal($this, $this->lunch, ['date' => '2026-11-05'], true);
    $row->update(['extra_amount' => 30]);
    AttendanceExtra::create(['attendance_id' => $row->id, 'menu_id' => $curd->id, 'name' => 'Curd', 'quantity' => 2, 'price' => 15]);
    $old = markMeal($this, $this->dinner, ['date' => '2026-11-06'], true);
    $old->update(['extra_amount' => 20, 'extra_meal_id' => '["' . $curd->id . '"]', 'meal_id' => null]);
    Payment::sole()->update(['amount' => 220]);
    expect(($this->bill)())->toMatchArray([
        'opening' => 0, 'closing' => 220,
        'meals' => [['name' => 'Lunch', 'count' => 1, 'amount' => 70], ['name' => 'Removed meal', 'count' => 1, 'amount' => 100]],
        'extras' => [['name' => 'Curd', 'count' => 2, 'amount' => 30], ['name' => 'Extras', 'count' => null, 'amount' => 20]],
    ]);
});

test('5.8 all in one', function () {
    $make = function (string $name, string $college, array $data = []) {
        $this->student = makeStudent(['name' => $name, 'middle_name' => null, 'last_name' => 'P', 'collage_name' => $college] + $data);
        markMeal($this, $this->lunch, ['date' => '2026-11-05']);

        return $this->student;
    };
    $make('Bina', 'South');
    $make('Asha', 'South');
    $left = $make('Left', 'North');
    $left->forceFill(['left_on' => '2026-11-10'])->save();
    $make('Gone', 'North')->delete();
    $paid = $make('Paid', 'North');
    payBillOf($this, 70);
    $this->travelTo('2026-12-25 10:00');
    makeStudent(['name' => 'Nothing']);
    $old = $make('Settled', 'North');
    payBillOf($this, 70);
    $names = fn ($query) => array_column(bills($this, $query)['bills'], 'name');
    expect($names('month=2026-11'))->toBe(['Gone P', 'Left P', 'Paid P', 'Settled P', 'Asha P', 'Bina P'])
        ->and($names('month=2026-12'))->toBe(['Gone P', 'Left P', 'Settled P', 'Asha P', 'Bina P'])
        ->and($names('month=2026-12&owing=1'))->toBe(['Gone P', 'Left P', 'Asha P', 'Bina P']);
    $admin = makeAdmin();
    makeBill($admin, 50);
    expect($names('month=2026-12'))->toHaveCount(5);
});

test('5.9 filters and limits', function () {
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    $this->student->forceFill(['collage_name' => 'North'])->save();
    expect(bills($this, 'month=2026-11&college=North')['bills'])->toHaveCount(1)->and(bills($this, 'month=2026-11&college=Unknown&owing[]=1')['bills'])->toHaveCount(1);
    $pdf = $this->get('/reports/statements/download?month=2026-11&college=North')->assertOk();
    expect($pdf->headers->get('Content-Disposition'))->toContain('bills_2026-11.pdf');
    $this->get('/reports/statements/download?month=2026-08&owing=1')->assertRedirect()->assertSessionHas('error', 'No bills for this choice.');
    User::factory()->count(500)->create(['role' => 'user'])->each(fn ($user) => makeBill($user, 10));
    $this->get('/reports/statements/download?month=2026-11')->assertRedirect()->assertSessionHas('error', '501 bills are too many for one file. Choose a college.');
});

test('5.10 300 students', function () {
    User::factory()->count(300)->create(['role' => 'user'])->each(function ($user) {
        makeBill($user, 140);
        foreach ([5, 6] as $day) {
            StudentAttendance::create(['student_id' => $user->id, 'meal_id' => $this->lunch->id, 'amount' => 70, 'date' => "2026-11-0$day", 'is_paid' => '0']);
        }
    });
    $start = microtime(true);
    $pdf = $this->get('/reports/statements/download?month=2026-11')->assertOk()->getContent();
    expect(preg_match_all('#/Type /Page\b(?!s)#', $pdf))->toBe(300);
    fwrite(STDERR, sprintf("\n300 bills: %.1f s, %d MB peak, %d KB\n", microtime(true) - $start, memory_get_peak_usage(true) / 1048576, strlen($pdf) / 1024));
});

test('5.11 query count', function () {
    $ids = User::factory()->count(50)->create(['role' => 'user'])->each(function ($user) {
        makeBill($user, 70);
        StudentAttendance::create(['student_id' => $user->id, 'meal_id' => $this->lunch->id, 'amount' => 70, 'date' => '2026-11-05', 'is_paid' => '0']);
    })->pluck('id')->all();
    $queries = function (array $ids) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(StudentStatement::class)->for($ids, '2026-11');

        return count(DB::getQueryLog());
    };
    expect($queries($ids))->toBe($queries(array_slice($ids, 0, 2)));
});

test('5.12 old payments', function () {
    markMeal($this, $this->lunch, ['date' => '2026-10-05']);
    payBillOf($this, 70);
    PaymentHistory::sole()->update(['before_amount' => null, 'payment_date' => '2026-10-20']);
    expect(($this->bill)('2026-09')['available'])->toBeFalse()->and(($this->bill)('2026-10')['available'])->toBeFalse()->and(($this->bill)('2026-11')['available'])->toBeTrue();
    $this->get("/bill/statement/{$this->student->id}?month=2026-10")->assertOk();
    expect(view('Bill.statement', ['bills' => [($this->bill)('2026-10')]])->render())->toContain('Not available');
});

test('5.13 months', function ($query) {
    foreach (["/bill/statement/{$this->student->id}", '/reports/statements/download'] as $url) {
        $this->get("$url?$query")->assertSessionHasErrors('month');
    }
    bills($this, $query);
})->with(['month=2026-13', 'month=2026-12', 'month[]=2026-11', '', 'month=2026-1']);

test('5.14 names', function () {
    $this->student->forceFill(['name' => '<script>x', 'canteen_id' => '../x'])->save();
    expect(view('Bill.statement', ['bills' => [($this->bill)()]])->render())->toContain('&lt;script&gt;x')->not->toContain('<script>');
    expect($this->get("/bill/statement/{$this->student->id}?month=2026-11")->headers->get('Content-Disposition'))->toContain('bill_x_2026-11.pdf');
    $this->student->forceFill(['name' => 'આશા', 'canteen_id' => 'C9'])->save();
    markMeal($this, $this->lunch, ['date' => '2026-11-05']);
    expect(view('Bill.statement', ['bills' => [($this->bill)()]])->render())->toContain('see canteen ID')->not->toContain('આશા');
    expect(bills($this)['unprintable'])->toBe([['name' => 'આશા Patel', 'canteen_id' => 'C9']]);
    $this->get("/bill/statement/{$this->student->id}?month=2026-11")->assertOk();
});

test('5.15 nothing', function () {
    expect(($this->bill)())->toMatchArray(['opening' => 0, 'closing' => 0, 'meals' => []]);
    expect(view('Bill.statement', ['bills' => [($this->bill)()]])->render())->toContain('No meals this month');
    $this->get("/bill/statement/{$this->student->id}?month=2026-11")->assertOk();
    $this->get('/bill/statement/999?month=2026-11')->assertNotFound();
    $this->get("/bill/statement/{$this->admin->id}?month=2026-11")->assertNotFound();
});

test('5.16 guest and student', function () {
    foreach (["/bill/statement/{$this->student->id}?month=2026-11", '/reports/statements', '/reports/statements/download?month=2026-11'] as $url) {
        $this->actingAs($this->student)->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }
});

test('5.17 random walk', function () {
    mt_srand(17);
    $this->travelTo('2026-06-01 10:00');
    $students = [$this->student, makeStudent(), makeStudent()];
    foreach (range(1, 150) as $step) {
        $this->travelTo(now()->addDay()->min('2026-11-25 10:00'));
        $this->student = $students[mt_rand(0, 2)];
        $date = today()->subDays(mt_rand(0, 40))->toDateString();
        $balance = Payment::where('student_id', $this->student->id)->first()?->balance ?? 0;
        match (mt_rand(0, 6)) {
            0, 1 => markMeal($this, mt_rand(0, 1) ? $this->lunch : $this->dinner, compact('date')),
            2 => cardGuest($this, $this->lunch, compact('date') + ['plates' => mt_rand(1, 3)]),
            3 => $balance > 0 ? payBillOf($this, mt_rand(1, $balance + 100)) : null,
            4 => $this->post('/bill/' . (PaymentHistory::active()->where('student_id', $this->student->id)->max('id') ?? 0) . '/cancel', ['reason' => 'Wrong']),
            5 => $this->post('/student-attendance/delete', ['id' => StudentAttendance::where('student_id', $this->student->id)->where('is_paid', '0')->value('id')]),
            6 => cancelGuest($this, GuestMeal::where('student_id', $this->student->id)->where('is_paid', '0')->value('id') ?? 0),
        };
        if ($step % 10) {
            continue;
        }
        foreach ($students as $student) {
            $opening = null;
            foreach (['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $month) {
                $bill = ($this->bill)($month, $student);
                expect($bill['closing'])->toBe($bill['opening'] + $bill['charged'] - $bill['paid']);
                expect($bill['opening'])->toBe($opening ?? $bill['opening']);
                $opening = $bill['closing'];
            }
            expect($opening)->toBe(Payment::where('student_id', $student->id)->first()?->balance ?? 0);
        }
    }
    expect(PaymentHistory::count())->toBeGreaterThan(5)->and(GuestMeal::count())->toBeGreaterThan(5);
});

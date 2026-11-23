<?php

use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\PaymentHistory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(makeAdmin());
    $this->asha = makeStudent(['name' => 'Asha', 'middle_name' => 'Raj', 'last_name' => 'Patel', 'canteen_id' => 'C1']);
});

function billNames($test, string $query): array
{
    $names = [];
    $test->get("/bill?$query")->assertOk()->assertInertia(function (Assert $page) use (&$names) {
        $names = array_column($page->toArray()['props']['bills']['data'], 'name');
    });

    return $names;
}

function payBill($test, array $data = [])
{
    $bill = makeBill($test->asha, 100);

    return $test->put("/bill/{$bill->id}", $data + ['student_id' => $test->asha->id, 'amount' => 50, 'type' => 'cash', 'month' => 'January']);
}

test('bills list, filter, search and sort', function () {
    $plain = ['middle_name' => null, 'last_name' => null];
    makeBill($this->asha, 100, 50, 0);
    makeBill(makeStudent($plain + ['name' => 'Bob', 'canteen_id' => 'C2']), 0, 0, 50);
    makeBill(makeStudent($plain + ['name' => 'Cat', 'canteen_id' => 'C3']), 10, 0, 10);

    $this->get('/bill')->assertInertia(fn (Assert $page) => $page->component('Bills/Index')->has('bills.data', 3)
        ->where('bills.data.0.balance', 150)->where('bills.data.0.student_id', $this->asha->id)
        ->where('bills.data.1.balance', -50)->where('bills.data.2.balance', 0));

    expect(billNames($this, 'show=due'))->toBe(['Asha']);
    expect(billNames($this, 'show=advance'))->toBe(['Bob']);
    expect(billNames($this, 'show=clear'))->toBe(['Cat']);
    expect(billNames($this, 'show=x'))->toHaveCount(3);
    expect(billNames($this, 'show[]=due'))->toHaveCount(3);
    foreach (['asha', 'raj', 'patel', 'c1'] as $search) {
        expect(billNames($this, "search=$search"))->toBe(['Asha']);
    }
    foreach (['name', 'canteen_id'] as $sort) {
        expect(billNames($this, "sort=$sort&dir=asc"))->toBe(['Asha', 'Bob', 'Cat']);
        expect(billNames($this, "sort=$sort&dir=desc"))->toBe(['Cat', 'Bob', 'Asha']);
    }
    expect(billNames($this, 'sort=balance&dir=asc'))->toBe(['Bob', 'Cat', 'Asha']);
    expect(billNames($this, 'sort=balance&dir=desc'))->toBe(['Asha', 'Cat', 'Bob']);
});

test('pay page', function () {
    makeBill($this->asha, 100, 50, 20);
    foreach (['2026-01-01' => 'January', '2026-02-01' => 'February'] as $date => $month) {
        PaymentHistory::create(['student_id' => $this->asha->id, 'amount' => 1, 'payment_date' => $date, 'type' => 'upi', 'month_name' => $month]);
    }
    $this->asha->delete();

    $this->get("/bill/{$this->asha->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Bills/Edit')
        ->where('bill.amount', 100)->where('bill.pending_amount', 50)->where('bill.advance_amount', 20)->where('bill.due', 130)
        ->where('student.name', 'Asha')
        ->where('receipts.0.month_name', 'February')->missing('receipts.0.amount')
        ->where('month', today()->format('F')));
});

test('no payment row returns to the list', function () {
    $this->get("/bill/{$this->asha->id}/edit")->assertRedirect('/bill')->assertSessionHas('error', 'There is no bill for this student yet.');
});

test('payment is saved', function (int $paid, int $pending, int $advance) {
    payBill($this, ['amount' => $paid])->assertRedirect('/bill')->assertSessionHas('success', "₹$paid saved for Asha Raj Patel.");

    expect(Payment::sole())->amount->toBe(0)->pending_amount->toBe($pending)->advance_amount->toBe($advance);
})->with(['full' => [100, 0, 0], 'partial' => [50, 50, 0], 'advance' => [130, 0, 30]]);

test('invalid payment is rejected', function () {
    payBill($this, ['amount' => '', 'type' => 'card', 'month' => 'Smarch'])->assertSessionHasErrors(['amount', 'type', 'month']);
    expect(PaymentHistory::count())->toBe(0);
});

test('receipt downloads, also for a deleted student', function () {
    MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    payBill($this);
    $this->asha->delete();
    $response = $this->get('/bill/' . PaymentHistory::sole()->id . '/receipt')->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf');
    $this->get('/bill/999/receipt')->assertNotFound();
});

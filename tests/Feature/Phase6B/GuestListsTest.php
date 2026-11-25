<?php

use App\Models\ActivityLog;
use App\Models\GuestMeal;
use App\Models\GuestMealExtra;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\SpecialPrice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    moneySetup($this);
    $this->today = today()->toDateString();
});
afterEach(fn () => DB::setDefaultConnection('sqlite'));

function queriesOf(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

test('5.1 home counts guests apart', function () {
    markMeal($this, $this->lunch, ['date' => $this->today]);
    $this->post('/student-attendance/store', ['user_id' => makeStudent()->id, 'meal_id' => $this->lunch->id, 'include_extra' => 'no', 'date' => $this->today]);
    cardGuest($this, $this->lunch, ['date' => $this->today, 'plates' => 3]);
    walkIn($this, $this->lunch, ['date' => $this->today, 'plates' => 4]);
    walkIn($this, $this->lunch, ['date' => $this->today, 'plates' => 5]);
    cancelGuest($this, GuestMeal::latest('id')->value('id'));
    cardGuest($this, $this->dinner, ['date' => today()->subDay()->toDateString(), 'plates' => 2]);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('meals.0', ['id' => $this->lunch->id, 'name' => 'Lunch', 'total' => 2, 'guests' => 7])
        ->where('meals.1.guests', 0)
        ->where('week.6.total', 2)->where('week.6.guests', 7)->where('week.5.total', 0)->where('week.5.guests', 2));
});

test('5.2 walk-in money', function () {
    walkIn($this, $this->lunch, ['date' => $this->today, 'plates' => 2]);
    walkIn($this, $this->lunch, ['date' => $this->today, 'method' => 'upi']);
    $tea = App\Models\MealPrice::create(['name' => 'Tea', 'slug' => 'tea', 'price' => 30]);
    walkIn($this, $tea, ['date' => $this->today]);
    cancelGuest($this, GuestMeal::latest('id')->value('id'));
    walkIn($this, $this->lunch, ['date' => today()->subDay()->toDateString()]);
    cardGuest($this, $this->lunch, ['date' => $this->today]);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('walkIn', ['cash' => 140, 'upi' => 70]));
});

test('5.3 history', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    cardGuest($this, $this->lunch, ['date' => '2026-01-09', 'guest_name' => 'Ravi', 'extras' => [$curd->id => 2]]);
    payBillOf($this, 100);
    cardGuest($this, $this->lunch, ['plates' => 2]);
    cardGuest($this, $this->dinner, ['date' => '2026-01-11']);
    cancelGuest($this, GuestMeal::latest('id')->value('id'));
    cardGuest($this, $this->dinner, ['date' => '2026-02-01']);
    cardGuest($this, $this->dinner, ['student_id' => makeStudent()->id]);
    walkIn($this, $this->dinner);

    $this->get("/student-attendance/show/{$this->student->id}?from=2026-01-01&to=2026-01-31")->assertInertia(fn (Assert $page) => $page
        ->has('guests', 3)
        ->where('guests.0', fn ($row) => $row['meal'] === 'Dinner' && $row['status'] === 'cancelled' && $row['reason'] === 'Wrong card')
        ->where('guests.1', fn ($row) => $row['plates'] === 2 && $row['amount'] === 140 && $row['status'] === 'unpaid')
        ->where('guests.2', fn ($row) => $row['guest_name'] === 'Ravi' && $row['status'] === 'paid'
            && collect($row['extras'])->toArray() === [['name' => 'Curd', 'quantity' => 2, 'total' => 30]]));
});

test('5.4 pay page', function () {
    markMeal($this, $this->lunch);
    cardGuest($this, $this->lunch, ['plates' => 2]);
    cardGuest($this, $this->lunch);
    cancelGuest($this, GuestMeal::latest('id')->value('id'));
    $this->get("/bill/{$this->student->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('bill.amount', 210)->where('bill.own', 70)->where('bill.guests', 140));
});

test('5.5 csv', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    markMeal($this, $this->dinner, ['date' => '2026-01-11']);
    cardGuest($this, $this->lunch, ['plates' => 2, 'guest_name' => 'Ravi', 'extras' => [$curd->id => 3]]);
    markMeal($this, $this->lunch);
    walkIn($this, $this->lunch, ['guest_name' => 'Meena']);
    walkIn($this, $this->lunch, ['plates' => 9]);
    cancelGuest($this, GuestMeal::latest('id')->value('id'));

    $all = $this->get('/student-attendance/download?from=2026-01-01&to=2026-01-31')->streamedContent();
    expect(array_filter(explode("\n", $all)))->toBe([
        '"Sr No.","Full Name","Canteen ID",Date,"Meal Name",Amount,"Extra Items","Extra Amount"',
        '1,"Asha Patel",C1,2026-01-10,Lunch,70.00,None,0.00',
        '2,"Asha Patel",C1,2026-01-10,"Lunch (guest x 2)",140.00,"Curd x 3",45.00',
        '3,"Walk-in guest",N/A,2026-01-10,"Lunch (guest x 1)",70.00,None,0.00',
        '4,"Asha Patel",C1,2026-01-11,Dinner,100.00,None,0.00',
    ]);
    $one = $this->get("/student-attendance/user/download?user_id={$this->student->id}&from=2026-01-01&to=2026-01-31")->streamedContent();
    expect($one)->toContain('Lunch (guest x 2)')->not->toContain('Walk-in')->not->toContain('Ravi');
});

test('5.6 receipt', function () {
    cardGuest($this, $this->lunch, ['plates' => 3]);
    cardGuest($this, $this->lunch, ['date' => '2026-02-01']);
    payBillOf($this, 100);
    $seen = null;
    Illuminate\Support\Facades\View::composer('Bill.receipt', function ($view) use (&$seen) {
        $seen = $view->getData()['guest_plates'];
    });
    $this->get('/bill/' . PaymentHistory::sole()->id . '/receipt')->assertOk();
    expect($seen)->toBe(3);

    $data = ['user_data' => $this->student, 'attedance_counts' => [['meal_name' => 'Lunch', 'total' => 4]], 'payment_month' => 'January', 'payment_date' => '2026-01-10', 'payment_mode' => 'CASH', 'total_amount' => 100, 'receipt_number' => '000001', 'payment' => PaymentHistory::sole()];
    foreach ([3, 0] as $plates) {
        expect(preg_replace('/\s+/', '', (string) view('Bill.receipt', $data + ['guest_plates' => $plates])))->toContain('<th>Lunch</th><th>Guestplates</th>')->toContain("<th>4</th><th>$plates</th>");
    }
});

test('5.7 a meal used by guests stays', function () {
    walkIn($this, $this->lunch);
    cancelGuest($this, GuestMeal::sole()->id);
    $this->delete("/meal-price/{$this->lunch->id}")->assertSessionHas('error', 'Lunch cannot be deleted because guest plates use it.');
    $this->assertModelExists($this->lunch);
});

test('5.8 special price over guests', function () {
    cardGuest($this, $this->lunch, ['plates' => 2]);
    walkIn($this, $this->lunch);
    walkIn($this, $this->lunch, ['plates' => 5]);
    cancelGuest($this, GuestMeal::latest('id')->value('id'));
    special($this, ['from_date' => '2026-01-10', 'to_date' => '2026-01-10'])->assertSessionHas('success', fn ($text) => str_contains($text, '3 meals already marked'));
    expect(SpecialPrice::sole()->marked())->toBe(3);
});

test('5.9 the check command', function (Closure $break, string $expected) {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    cardGuest($this, $this->lunch, ['plates' => 2, 'extras' => [$curd->id => 1]]);
    cardGuest($this, $this->lunch, ['date' => '2026-01-11']);
    walkIn($this, $this->lunch);
    $this->artisan('canteen:check-money')->assertExitCode(0);

    $break($this);
    $this->artisan('canteen:check-money')->expectsOutputToContain($expected)->assertExitCode(1);
})->with([
    'balance' => [fn () => Payment::query()->update(['amount' => 1]), 'bill 1: amount 1, unpaid entries 225'],
    'no bill' => [fn () => Payment::query()->delete(), 'guest 1: no bill'],
    'formula' => [fn () => DB::table('guest_meals')->where('id', 3)->update(['amount' => 71]), 'guest 3: amount 71, expected 70'],
    'card without student' => [fn () => DB::table('guest_meals')->where('id', 2)->update(['student_id' => null]), 'guest 2: fields do not fit its type'],
    'card with method' => [fn () => DB::table('guest_meals')->where('id', 2)->update(['method' => 'cash']), 'guest 2: fields do not fit its type'],
    'walk-in with student' => [fn ($t) => DB::table('guest_meals')->where('id', 3)->update(['student_id' => $t->student->id]), 'guest 3: fields do not fit its type'],
    'walk-in without method' => [fn () => DB::table('guest_meals')->where('id', 3)->update(['method' => null]), 'guest 3: fields do not fit its type'],
    'walk-in unpaid' => [fn () => DB::table('guest_meals')->where('id', 3)->update(['is_paid' => '0']), 'guest 3: fields do not fit its type'],
    'walk-in linked' => [fn ($t) => DB::table('guest_meals')->where('id', 3)->update(['payment_history_id' => guestReceipt($t->student)]), 'guest 3: fields do not fit its type'],
    'unpaid but linked' => [fn ($t) => DB::table('guest_meals')->where('id', 2)->update(['payment_history_id' => guestReceipt($t->student)]), 'guest 2: unpaid but linked to payment 1'],
    'cancelled payment' => [fn ($t) => DB::table('guest_meals')->where('id', 2)->update(['is_paid' => '1', 'payment_history_id' => guestReceipt($t->student, ['cancelled_at' => now()])]), 'guest 2: linked to cancelled payment 1'],
    'other student' => [fn () => DB::table('guest_meals')->where('id', 2)->update(['is_paid' => '1', 'payment_history_id' => guestReceipt(makeStudent())]), 'guest 2: payment 1 belongs to another student'],
    'cancelled but paid' => [fn () => DB::table('guest_meals')->where('id', 2)->update(['is_paid' => '1', 'cancelled_at' => now()]), 'guest 2: cancelled but paid or linked'],
    'cancelled but linked' => [fn ($t) => DB::table('guest_meals')->where('id', 2)->update(['payment_history_id' => guestReceipt($t->student), 'cancelled_at' => now()]), 'guest 2: cancelled but paid or linked'],
    'extras' => [fn () => GuestMealExtra::query()->update(['quantity' => 2]), 'guest 1: extra amount 15, extra items 30'],
    'paid without link' => [fn () => DB::table('guest_meals')->where('id', 2)->update(['is_paid' => '1']), 'guest 2: paid but not linked'],
]);

function guestReceipt($student, array $data = []): int
{
    return PaymentHistory::create($data + ['student_id' => $student->id, 'amount' => 1, 'payment_date' => '2026-01-12', 'type' => 'cash', 'month_name' => 'January', 'before_amount' => 1, 'before_pending' => 0, 'before_advance' => 0])->id;
}

test('5.9 migration', function () {
    separateDatabase();
    $this->artisan('canteen:check-money')->assertExitCode(0);
    Artisan::call('migrate:rollback', ['--step' => 5]);
    expect(Illuminate\Support\Facades\Schema::hasTable('guest_meals'))->toBeFalse();
    $this->artisan('canteen:check-money')->assertExitCode(0);
    Artisan::call('migrate');
    $meal = App\Models\MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    DB::table('guest_meals')->insert(['type' => 'walk_in', 'meal_id' => $meal->id, 'meal_name' => 'Lunch', 'date' => '2026-01-10', 'plates' => 1, 'price' => 70, 'extra_amount' => 0, 'amount' => 70, 'method' => 'cash', 'is_paid' => '1', 'token' => 'x', 'user_name' => 'x']);
    expect(fn () => Artisan::call('migrate:rollback', ['--step' => 5]))->toThrow(RuntimeException::class);
    expect(DB::table('guest_meals')->count())->toBe(1);
});

test('5.10 query counts', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    $add = function () use ($curd) {
        cardGuest($this, $this->lunch, ['date' => $this->today, 'extras' => [$curd->id => 1]]);
        walkIn($this, $this->lunch, ['date' => $this->today, 'extras' => [$curd->id => 1]]);
    };
    $add();
    $urls = ['/dashboard', '/student-attendance/guest', "/student-attendance/show/{$this->student->id}", "/bill/{$this->student->id}/edit", "/student-attendance/download?from={$this->today}&to={$this->today}", '/meal-price', "/user/{$this->student->id}"];
    $count = fn () => array_map(fn ($url) => queriesOf(fn () => $this->get($url)->assertOk()), $urls);
    $few = $count();
    foreach (range(1, 19) as $n) {
        $add();
    }
    expect($count())->toBe($few);
});

test('5.11 a deleted item keeps its name', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    cardGuest($this, $this->lunch, ['extras' => [$curd->id => 2]]);
    $this->delete("/menus/{$curd->id}")->assertSessionHas('success');
    expect(GuestMealExtra::sole()->menu_id)->toBeNull();
    $this->get("/student-attendance/show/{$this->student->id}?from=2026-01-01&to=2026-01-31")
        ->assertInertia(fn (Assert $page) => $page->where('guests.0.extras.0.name', 'Curd'));
});

test('student page and changes filter', function () {
    cardGuest($this, $this->lunch, ['date' => $this->today, 'plates' => 3]);
    $this->get("/user/{$this->student->id}")->assertInertia(fn (Assert $page) => $page->where('guests', 3));
    ActivityLog::record('meal.added', null, 'Other');
    $this->get('/changes?type=guest')->assertInertia(fn (Assert $page) => $page->has('changes.data', 1)->where('filters.type', 'guest'));
});

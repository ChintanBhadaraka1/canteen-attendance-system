<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function makeAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function makeStudent(array $data = []): User
{
    return User::factory()->create($data + ['role' => 'user']);
}

function makeBill(User $student, int $amount, int $pending = 0, int $advance = 0): Payment
{
    return Payment::create([
        'student_id' => $student->id, 'amount' => $amount, 'pending_amount' => $pending, 'advance_amount' => $advance,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
    ]);
}

// Asha with Lunch at 70 and Dinner at 100, signed in as an admin.
function moneySetup($test): void
{
    $test->admin = makeAdmin();
    $test->actingAs($test->admin);
    $test->lunch = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    $test->dinner = MealPrice::create(['name' => 'Dinner', 'slug' => 'dinner', 'price' => 100]);
    $test->student = makeStudent(['name' => 'Asha', 'middle_name' => null, 'last_name' => 'Patel', 'canteen_id' => 'C1']);
}

function markMeal($test, MealPrice $meal, array $data = [], bool $returnEntry = false)
{
    $response = $test->post('/student-attendance/store', $data + [
        'user_id' => $test->student->id, 'meal_id' => $meal->id, 'include_extra' => 'no', 'date' => '2026-01-10',
    ]);

    return $returnEntry ? StudentAttendance::latest('id')->first() : $response;
}

function bulk($test, array $students, array $data = [])
{
    return $test->post('/student-attendance/bulk', $data + [
        'students' => $students, 'meal_id' => $test->lunch->id, 'date' => '2026-01-10', 'price' => 70,
    ]);
}

function payBillOf($test, int $amount, array $data = [])
{
    $bill = Payment::where('student_id', $test->student->id)->sole();

    return $test->put("/bill/{$bill->id}", $data + [
        'amount' => $amount, 'type' => 'cash', 'month' => 'January', 'due' => $bill->balance,
    ]);
}

function parts(): array
{
    $bill = Payment::orderBy('id')->firstOrFail();

    return [$bill->amount, $bill->pending_amount, $bill->advance_amount];
}

// Migrations cannot be rolled back inside RefreshDatabase's transaction.
function separateDatabase(): void
{
    config(['database.connections.separate' => ['database' => ':memory:'] + config('database.connections.sqlite')]);
    DB::setDefaultConnection('separate');
    Artisan::call('migrate');
}

function dish(string $name, array $data = []): App\Models\Menus
{
    return App\Models\Menus::create($data + ['name' => $name, 'slug' => str($name)->slug()->value()]);
}

function optionId(string $name): int
{
    return App\Models\MenuOption::where('name', $name)->orderBy('id')->firstOrFail()->id;
}

function planMenu($test, array $items, array $data = [])
{
    return $test->post('/menu-plan', $data + [
        'meal_id' => $test->lunch->id, 'from' => today()->toDateString(), 'repeat' => 'once',
        'items' => array_map(fn ($item) => $item->id, $items),
    ]);
}

function special($test, array $data = [])
{
    return $test->post("/meal-price/{$test->lunch->id}/special", $data + [
        'price' => 90, 'from_date' => '2026-12-08', 'to_date' => '2026-12-10', 'reason' => 'Diwali',
    ]);
}

// Plates on the card of $test->student; the total is the right one unless given.
function guestData(MealPrice $meal, array $data = []): array
{
    $data += ['meal_id' => $meal->id, 'date' => '2026-01-10', 'plates' => 1, 'extras' => [], 'guest_name' => null, 'token' => (string) Illuminate\Support\Str::uuid()];
    $extras = collect(is_array($data['extras']) ? $data['extras'] : [])->map(fn ($quantity, $id) => (int) $quantity * (int) App\Models\Menus::find($id)?->price)->sum();

    return $data + ['expected_total' => (int) $data['plates'] * $meal->priceOn(is_string($data['date']) ? $data['date'] : '2026-01-10') + $extras];
}

function cardGuest($test, MealPrice $meal, array $data = [])
{
    return $test->post('/student-attendance/guest/card', guestData($meal, $data + ['student_id' => $test->student->id]));
}

function walkIn($test, MealPrice $meal, array $data = [])
{
    return $test->post('/student-attendance/guest/walk-in', guestData($meal, $data + ['method' => 'cash']));
}

function cancelGuest($test, int $id, mixed $reason = 'Wrong card')
{
    return $test->post("/student-attendance/guest/$id/cancel", ['reason' => $reason]);
}

function token(): string
{
    return (string) Illuminate\Support\Str::uuid();
}

function stockItem(string $name, array $data = []): App\Models\StockItem
{
    return App\Models\StockItem::create($data + ['name' => $name, 'unit' => 'kg'])->fresh();
}

// [item, quantity, amount, unit] rows for the stock forms.
function stockRows(array $rows): array
{
    return array_map(fn ($row) => ['item' => $row[0]->id, 'quantity' => $row[1], 'unit' => $row[3] ?? $row[0]->unit]
        + (isset($row[2]) ? ['amount' => $row[2]] : []), $rows);
}

function buy($test, array $rows, array $data = [])
{
    return $test->post('/purchases', $data + ['date' => today()->toDateString(), 'token' => token(), 'method' => 'cash', 'rows' => stockRows($rows)]);
}

function stockOut($test, string $type, array $rows, array $data = [])
{
    return $test->post("/stock/$type", $data + ['date' => today()->toDateString(), 'token' => token(), 'rows' => stockRows($rows)]
        + ($type === 'waste' ? ['reason_id' => optionId('Spoiled')] : []));
}

function countStock($test, array $rows, array $data = [])
{
    return $test->post('/stock/count', $data + [
        'date' => today()->toDateString(), 'token' => token(), 'reason_text' => 'Counted again',
        'rows' => array_map(fn ($row) => ['item' => $row[0]->id, 'quantity' => $row[1], 'unit' => $row[0]->unit, 'expected' => $row[2] ?? $row[0]->fresh()->on_hand], $rows),
    ]);
}

function cancelStock($test, int $id, mixed $reason = 'Wrong entry')
{
    return $test->post("/stock/entries/$id/cancel", ['reason' => $reason]);
}

// [on hand, value]
function stockOf(App\Models\StockItem $item): array
{
    $item = $item->fresh();

    return [$item->on_hand, $item->value];
}

function lastEntry(): App\Models\StockEntry
{
    return App\Models\StockEntry::orderByDesc('id')->firstOrFail();
}

function module($test, string $module, mixed $on = true)
{
    return $test->put('/settings/modules', ['module' => $module, 'on' => $on]);
}

function addExpense($test, array $data = [])
{
    return $test->post('/expenses', $data + ['type_id' => optionId('Rent'), 'date' => today()->toDateString(), 'amount' => '100', 'method' => 'cash', 'token' => token()]);
}

function hire($test, array $data = []): App\Models\Staff
{
    $test->post('/staff', $data + ['name' => 'Ramesh Cook', 'phone' => '9876543210', 'job_id' => optionId('Cook'), 'joined_on' => '2026-09-15', 'salary' => '9000'])->assertSessionHasNoErrors();

    return App\Models\Staff::latest('id')->first();
}

function staffEntry($test, App\Models\Staff $staff, string $type, string $month, string $amount, array $data = [])
{
    return $test->post("/staff/{$staff->id}/entries", $data + ['type' => $type, 'month' => $month, 'date' => today()->toDateString(), 'amount' => $amount, 'note' => 'Note', 'token' => token()]
        + ($type === 'payment' ? ['method' => 'cash'] : []));
}

function cashCheck($test, array $data = [])
{
    $data += ['date' => today()->toDateString(), 'put_in' => '', 'taken_out' => '', 'note' => '', 'token' => token(), 'opening' => '1000'];
    $cash = app(App\Services\CashService::class)->expected($data['date']);
    $opening = $cash['opening'] ?? App\Support\Rupees::parse($data['opening']) ?? 0;
    $expected = $opening + $cash['change'] + (App\Support\Rupees::parse($data['put_in']) ?? 0) - (App\Support\Rupees::parse($data['taken_out']) ?? 0);

    return $test->post('/cash-check', $data + ['expected' => $expected, 'counted' => App\Support\Rupees::show(max(0, $expected))]);
}

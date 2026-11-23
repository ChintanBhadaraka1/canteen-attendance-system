<?php

use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('role', 'admin')->firstOrFail();
    $this->student = User::create([
        'name' => 'Asha', 'middle_name' => 'Raj', 'last_name' => 'Patel', 'email' => 'asha@example.com',
        'collage_name' => 'Test College', 'canteen_id' => 'C1', 'phone_number' => '9999999999', 'role' => 'user',
    ]);
    $this->lunch = MealPrice::where('slug', 'lunch')->firstOrFail();
});

function mark($test, array $data = [])
{
    return $test->actingAs($test->admin)->post('/student-attendance/store', $data + [
        'user_id' => $test->student->id, 'meal_id' => $test->lunch->id,
        'include_extra' => 'no', 'date' => '2026-01-10',
    ]);
}

function pay($test, $amount)
{
    $payment = Payment::where('student_id', $test->student->id)->firstOrFail();

    return $test->actingAs($test->admin)->put("/bill/{$payment->id}", [
        'student_id' => $test->student->id, 'amount' => $amount, 'type' => 'cash', 'month' => 'January',
    ]);
}

test('login accepts valid and rejects invalid credentials', function () {
    User::factory()->create(['email' => 'login@example.com', 'password' => 'secret-pass', 'role' => 'admin']);

    $this->post('/login', ['email' => 'login@example.com', 'password' => 'wrong']);
    $this->assertGuest();

    $this->post('/login', ['email' => 'login@example.com', 'password' => 'secret-pass'])->assertRedirect('/dashboard');
    $this->assertAuthenticated();
});

test('guests are sent to login', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with(['/dashboard', '/user', '/menus', '/meal-price', '/student-attendance', '/bill']);

test('every screen renders', function () {
    mark($this);
    $menu = Menus::create(['name' => 'Curd', 'slug' => 'curd', 'is_extra' => '1', 'price' => 15]);
    $id = $this->student->id;

    foreach ([
        '/dashboard', '/user', '/user/create', "/user/$id", "/user/$id/edit",
        '/menus', '/menus/create', "/menus/{$menu->id}/edit",
        '/meal-price', '/meal-price/create', "/meal-price/{$this->lunch->id}/edit",
        '/student-attendance', "/student-attendance/create/$id", "/student-attendance/show/$id",
        '/bill', "/bill/$id/edit",
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk();
    }
});

test('attendance adds meal and extras to the balance', function () {
    Menus::create(['name' => 'Curd', 'slug' => 'curd', 'is_extra' => '1', 'price' => 15]);
    mark($this, ['include_extra' => 'yes', 'extra_items' => ['curd' => 2]]);

    expect(StudentAttendance::sole())->amount->toEqual(70)->extra_amount->toEqual(30);
    expect(Payment::sole()->amount)->toEqual(100);
});

test('the same meal cannot be marked twice on one date', function () {
    mark($this);
    mark($this)->assertSessionHas('error');

    expect(StudentAttendance::count())->toBe(1)->and(Payment::sole()->amount)->toEqual(70);
});

test('deleting attendance reduces the balance', function () {
    mark($this);
    $this->post('/student-attendance/delete', ['id' => StudentAttendance::sole()->id])->assertRedirect();

    expect(StudentAttendance::count())->toBe(0)->and(Payment::sole()->amount)->toEqual(0);
});

test('payments set pending and advance', function (int $paid, int $pending, int $advance) {
    mark($this);
    pay($this, $paid)->assertRedirect(route('bill.index'));

    expect(Payment::sole())->amount->toEqual(0)->pending_amount->toEqual($pending)->advance_amount->toEqual($advance);
    expect(StudentAttendance::sole()->is_paid)->toEqual(1);
    expect(PaymentHistory::sole()->amount)->toEqual($paid);
})->with([
    'full' => [70, 0, 0],
    'partial' => [50, 20, 0],
    'advance' => [100, 0, 30],
]);

test('attendance before the last paid date is rejected', function () {
    mark($this);
    pay($this, 70);
    mark($this, ['date' => '2026-01-09'])->assertSessionHas('error');

    expect(StudentAttendance::count())->toBe(1);
});

test('csv exports contain the attendance', function (string $url) {
    mark($this);
    $query = http_build_query(['from' => '2026-01-01', 'to' => '2026-01-31', 'user_id' => $this->student->id]);
    $response = $this->actingAs($this->admin)->get("$url?$query")->assertOk();

    expect($response->streamedContent())->toContain('Asha Raj Patel')->toContain('Lunch');
})->with(['/student-attendance/download', '/student-attendance/user/download']);

test('the receipt downloads as a pdf', function () {
    mark($this);
    pay($this, 70);
    $response = $this->get('/bill/' . PaymentHistory::sole()->id . '/receipt')->assertOk();

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('students can be created, updated and soft deleted', function () {
    $data = ['name' => 'Ravi', 'collage_name' => 'Test College', 'email' => 'ravi@example.com', 'canteen_id' => 'C2', 'phone_number' => '8888888888'];
    $this->actingAs($this->admin)->post('/user', $data)->assertSessionHasNoErrors();
    $ravi = User::where('email', 'ravi@example.com')->firstOrFail();

    $this->put("/user/{$ravi->id}", ['name' => 'Ravindra'] + $data)->assertSessionHasNoErrors();
    expect($ravi->fresh()->name)->toBe('Ravindra');

    $this->delete("/user/{$ravi->id}")->assertRedirect('/user');
    $this->assertSoftDeleted($ravi);
});

test('menus and meal prices can be created, updated and deleted', function (string $url, string $model) {
    $this->actingAs($this->admin)->post($url, ['name' => 'Snack', 'price' => 20])->assertSessionHasNoErrors();
    $row = $model::where('slug', 'snack')->firstOrFail();

    $this->put("$url/{$row->id}", ['name' => 'Evening Snack', 'price' => 25])->assertSessionHasNoErrors();
    expect($row->fresh()->slug)->toBe('evening-snack');

    $this->delete("$url/{$row->id}")->assertRedirect($url);
    expect($model::find($row->id))->toBeNull();
})->with([['/menus', Menus::class], ['/meal-price', MealPrice::class]]);

test('invalid student input is rejected', function () {
    $this->actingAs($this->admin)->post('/user', ['name' => 'A'])
        ->assertSessionHasErrors(['name', 'collage_name', 'email', 'canteen_id', 'phone_number']);
});

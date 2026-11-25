<?php

use App\Models\ActivityLog;
use App\Models\GuestMeal;
use App\Models\GuestMealExtra;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use Illuminate\Support\Str;

beforeEach(function () {
    moneySetup($this);
    $this->breakfast = MealPrice::create(['name' => 'Breakfast', 'slug' => 'breakfast', 'price' => 30]);
    $this->curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
});

test('1.1 own meal and one plate', function () {
    markMeal($this, $this->breakfast);
    cardGuest($this, $this->breakfast)->assertSessionHasNoErrors()->assertSessionHas('success', '1 guest plate of Breakfast added for Asha Patel (₹30).');
    expect(Payment::sole()->amount)->toBe(60)->and(StudentAttendance::count())->toBe(1);
    expect(GuestMeal::sole()->refresh())->type->toBe('card')->meal_name->toBe('Breakfast')->plates->toBe(1)->price->toBe(30)->amount->toBe(30)
        ->is_paid->toBe('0')->method->toBeNull()->created_by->toBe($this->admin->id)->user_name->toBe($this->admin->full_name);
    expect(ActivityLog::latest('id')->first())->action->toBe('guest.card_added')->student_id->toBe($this->student->id)
        ->summary->toBe('1 guest plate of Breakfast on 10 January 2026 for Asha Patel (₹30)');
});

test('1.2 three friends', function () {
    markMeal($this, $this->breakfast);
    cardGuest($this, $this->breakfast, ['plates' => 2]);
    cardGuest($this, $this->breakfast);
    expect(Payment::sole()->amount)->toBe(120)->and(GuestMeal::count())->toBe(2)->and(StudentAttendance::count())->toBe(1);
});

test('1.3 no own meal', function () {
    cardGuest($this, $this->breakfast);
    expect(Payment::sole()->amount)->toBe(30)->and(StudentAttendance::count())->toBe(0);
    markMeal($this, $this->breakfast)->assertSessionHas('success');
    expect(Payment::sole()->amount)->toBe(60);
});

test('1.4 the own meal stays single', function () {
    cardGuest($this, $this->breakfast);
    markMeal($this, $this->breakfast);
    markMeal($this, $this->breakfast)->assertSessionHas('error', 'Breakfast is already marked for this date.');
    expect(Payment::sole()->amount)->toBe(60);
});

test('1.5 extras', function () {
    cardGuest($this, $this->breakfast, ['plates' => 2, 'extras' => [$this->curd->id => 3]]);
    expect(Payment::sole()->amount)->toBe(105);
    expect(GuestMeal::sole())->extra_amount->toBe(45)->amount->toBe(105);
    expect(GuestMealExtra::sole())->menu_id->toBe($this->curd->id)->name->toBe('Curd')->quantity->toBe(3)->price->toBe(15);
});

test('1.6 special price', function () {
    special($this, ['price' => 50, 'from_date' => '2026-01-10', 'to_date' => '2026-01-10']);
    cardGuest($this, $this->lunch, ['plates' => 2]);
    expect(Payment::sole()->amount)->toBe(100);
});

test('1.7 another total is refused', function () {
    cardGuest($this, $this->breakfast, ['plates' => 3, 'expected_total' => 60])
        ->assertSessionHasErrors(['guest' => 'The price has changed. The total is now ₹90. Please check and save again.']);
    expect(GuestMeal::count())->toBe(0)->and(Payment::count())->toBe(0);
});

test('1.8 the same token', function () {
    $token = (string) Str::uuid();
    cardGuest($this, $this->breakfast, ['token' => $token]);
    cardGuest($this, $this->breakfast, ['token' => $token])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(GuestMeal::count())->toBe(1)->and(Payment::sole()->amount)->toBe(30);
    cardGuest($this, $this->breakfast, ['token' => $token, 'student_id' => makeStudent()->id])->assertSessionHasErrors('guest');
    walkIn($this, $this->breakfast, ['token' => $token])->assertSessionHasErrors('guest');
    $this->actingAs(makeAdmin());
    cardGuest($this, $this->breakfast, ['token' => $token])->assertSessionHasErrors('guest');
    expect(GuestMeal::count())->toBe(1)->and(Payment::sole()->amount)->toBe(30);
});

test('1.9 bad input', function (array $data) {
    if (($data['special'] ?? null) === 'off') {
        $this->breakfast->update(['active' => false]);
    }
    if (($data['special'] ?? null) === 'item') {
        $this->curd->update(['available' => false]);
        $data['extras'] = [$this->curd->id => 1];
    }
    unset($data['special']);
    cardGuest($this, $this->breakfast, $data + ['expected_total' => 30])->assertSessionHasErrors();
    expect(GuestMeal::count())->toBe(0)->and(Payment::count())->toBe(0);
})->with([
    'plates 0' => [['plates' => 0]], 'plates 21' => [['plates' => 21]], 'plates 1.5' => [['plates' => 1.5]], 'plates x' => [['plates' => 'x']], 'plates list' => [['plates' => [1]]],
    'future' => [['date' => '2099-01-01']], 'date list' => [['date' => ['2026-01-10']]], 'no date' => [['date' => null]],
    'bad meal' => [['meal_id' => 999]], 'meal list' => [['meal_id' => [1]]], 'off meal' => [['special' => 'off']], 'item off' => [['special' => 'item']],
    'unknown item' => [['extras' => [999 => 1]]], 'extras text' => [['extras' => 'x']], 'quantity 100' => [['extras' => [1 => 100]]],
    'name 61' => [['guest_name' => str_repeat('a', 61)]], 'name list' => [['guest_name' => ['a']]],
    'no token' => [['token' => null]], 'bad token' => [['token' => 'abc']], 'no total' => [['expected_total' => null]],
]);

test('1.9 before the last paid date', function () {
    markMeal($this, $this->lunch, ['date' => '2026-01-11']);
    payBillOf($this, 70);
    cardGuest($this, $this->breakfast)->assertSessionHasErrors(['guest' => 'This date is already paid, so guest plates cannot be added.']);
    expect(GuestMeal::count())->toBe(0);
});

test('1.10 who cannot have guests', function () {
    $left = makeStudent(['left_on' => '2026-01-01']);
    $deleted = makeStudent();
    $deleted->delete();
    foreach ([$left->id, $deleted->id, $this->admin->id, 999, [1], null] as $id) {
        cardGuest($this, $this->breakfast, ['student_id' => $id])->assertSessionHasErrors();
    }
    expect(GuestMeal::count())->toBe(0)->and(Payment::count())->toBe(0);
});

test('1.11 advance', function () {
    makeBill($this->student, 0, 0, 100);
    cardGuest($this, $this->breakfast, ['plates' => 2]);
    expect(parts())->toBe([60, 0, 100])->and(Payment::sole()->balance)->toBe(-40);
});

test('1.12 a later price', function () {
    cardGuest($this, $this->breakfast);
    $this->breakfast->update(['price' => 99]);
    expect(GuestMeal::sole())->price->toBe(30)->amount->toBe(30)->and(Payment::sole()->amount)->toBe(30);
});

test('1.13 errors save nothing', function (string $where) {
    $papad = dish('Papad', ['is_extra' => '1', 'price' => 10]);
    $where === 'log'
        ? ActivityLog::creating(fn () => throw new Exception('no'))
        : GuestMealExtra::creating(fn ($row) => throw_if($row->name === 'Papad', new Exception('no')));
    cardGuest($this, $this->breakfast, ['extras' => [$this->curd->id => 1, $papad->id => 1]])->assertServerError();
    expect(GuestMeal::count())->toBe(0)->and(GuestMealExtra::count())->toBe(0)->and(Payment::count())->toBe(0);
})->with(['log', 'extra']);

test('1.14 money sent by the browser is ignored', function () {
    cardGuest($this, $this->breakfast, ['price' => 1, 'amount' => 1, 'is_paid' => '1', 'type' => 'walk_in', 'method' => 'cash', 'extra_amount' => 5]);
    expect(GuestMeal::sole()->refresh())->price->toBe(30)->amount->toBe(30)->extra_amount->toBe(0)->is_paid->toBe('0')->type->toBe('card')->method->toBeNull();
});

test('1.15 access', function () {
    $this->actingAs($this->student);
    cardGuest($this, $this->breakfast)->assertForbidden();
    $this->get('/student-attendance/guest')->assertForbidden();
    auth()->logout();
    cardGuest($this, $this->breakfast)->assertRedirect('/login');
    $this->get('/student-attendance/guest')->assertRedirect('/login');
    expect(GuestMeal::count())->toBe(0);
});

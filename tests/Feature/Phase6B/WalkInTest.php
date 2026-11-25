<?php

use App\Models\ActivityLog;
use App\Models\GuestMeal;
use App\Models\GuestMealExtra;
use App\Models\Payment;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

test('3.1 cash', function () {
    makeBill($this->student, 0);
    walkIn($this, $this->lunch, ['plates' => 2])->assertSessionHas('success', '2 plates of Lunch saved. ₹140 received by cash.');
    expect(GuestMeal::sole()->refresh())->type->toBe('walk_in')->student_id->toBeNull()->payment_history_id->toBeNull()
        ->is_paid->toBe('1')->method->toBe('cash')->amount->toBe(140);
    expect(parts())->toBe([0, 0, 0])->and(Payment::count())->toBe(1);
    expect(ActivityLog::latest('id')->first())->action->toBe('guest.walk_in_added')->student_id->toBeNull()
        ->summary->toBe('2 plates of Lunch on 10 January 2026 to a walk-in guest (₹140)');
});

test('3.2 upi, extras and a name', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    walkIn($this, $this->lunch, ['method' => 'upi', 'extras' => [$curd->id => 2], 'guest_name' => 'Ravi'])->assertSessionHas('success', '1 plate of Lunch saved. ₹100 received by UPI.');
    expect(GuestMeal::sole())->method->toBe('upi')->guest_name->toBe('Ravi')->amount->toBe(100)->and(GuestMealExtra::sole()->quantity)->toBe(2);
    expect(ActivityLog::pluck('summary')->implode(' '))->not->toContain('Ravi');
});

test('3.3 bad input', function (array $data) {
    if ($data === ['off']) {
        $this->lunch->update(['active' => false]);
        $data = [];
    }
    walkIn($this, $this->lunch, $data + ['expected_total' => 70])->assertSessionHasErrors();
    expect(GuestMeal::count())->toBe(0);
})->with([
    'no method' => [['method' => null]], 'card' => [['method' => 'card']], 'method list' => [['method' => ['cash']]],
    'plates 0' => [['plates' => 0]], 'plates 21' => [['plates' => 21]], 'plates x' => [['plates' => 'x']],
    'future' => [['date' => '2099-01-01']], 'too old' => [fn () => ['date' => today()->subDays(367)->toDateString()]], 'off' => [['off']],
]);

test('3.3 366 days back', function () {
    walkIn($this, $this->lunch, ['date' => today()->subDays(366)->toDateString()])->assertSessionHasNoErrors();
    expect(GuestMeal::count())->toBe(1);
});

test('3.4 a student is ignored', function () {
    walkIn($this, $this->lunch, ['student_id' => $this->student->id, 'is_paid' => '0', 'payment_history_id' => 1]);
    expect(GuestMeal::sole()->refresh())->student_id->toBeNull()->is_paid->toBe('1')->payment_history_id->toBeNull()->and(Payment::count())->toBe(0);
});

test('3.5 the same token', function () {
    $token = (string) Str::uuid();
    walkIn($this, $this->lunch, ['token' => $token]);
    walkIn($this, $this->lunch, ['token' => $token])->assertSessionHasNoErrors();
    expect(GuestMeal::count())->toBe(1);
});

test('3.6 prices', function () {
    special($this, ['from_date' => '2026-01-10', 'to_date' => '2026-01-10']);
    walkIn($this, $this->lunch);
    expect(GuestMeal::sole()->amount)->toBe(90);
    walkIn($this, $this->lunch, ['expected_total' => 70])->assertSessionHasErrors(['guest' => 'The price has changed. The total is now ₹90. Please check and save again.']);
    expect(GuestMeal::count())->toBe(1);
});

test('3.7 a name is text', function () {
    walkIn($this, $this->lunch, ['guest_name' => '<script>alert(1)</script>']);
    $this->get('/student-attendance/guest?date=2026-01-10')->assertDontSee('<script>alert(1)', false)
        ->assertInertia(fn (Assert $page) => $page->where('recent.0.guest_name', '<script>alert(1)</script>'));
});

test('3.8 access', function () {
    $this->actingAs($this->student);
    walkIn($this, $this->lunch)->assertForbidden();
    auth()->logout();
    walkIn($this, $this->lunch)->assertRedirect('/login');
    expect(GuestMeal::count())->toBe(0);
});

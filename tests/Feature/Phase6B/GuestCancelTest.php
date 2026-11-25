<?php

use App\Models\ActivityLog;
use App\Models\GuestMeal;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;

beforeEach(fn () => moneySetup($this));

test('4.1 unpaid plates with extras', function () {
    $curd = dish('Curd', ['is_extra' => '1', 'price' => 15]);
    markMeal($this, $this->lunch);
    cardGuest($this, $this->lunch, ['plates' => 2, 'extras' => [$curd->id => 2]]);
    cancelGuest($this, GuestMeal::sole()->id)->assertSessionHas('success', '2 guest plates of Lunch cancelled. ₹170 taken off the bill of Asha Patel.');
    expect(parts())->toBe([70, 0, 0]);
    expect(GuestMeal::sole())->cancelled_at->not->toBeNull()->cancelled_by->toBe($this->admin->id)->cancel_reason->toBe('Wrong card')->amount->toBe(170);
    expect(ActivityLog::latest('id')->first())->action->toBe('guest.cancelled')->student_id->toBe($this->student->id)
        ->summary->toBe('2 guest plates of Lunch on 10 January 2026 for Asha Patel (₹170) cancelled: Wrong card');
});

test('4.2 paid plates', function () {
    cardGuest($this, $this->lunch);
    payBillOf($this, 70);
    $id = GuestMeal::sole()->id;
    cancelGuest($this, $id)->assertSessionHasErrors(['reason' => 'This plate is already paid. Cancel the payment first.']);
    expect(GuestMeal::sole()->cancelled_at)->toBeNull()->and(parts())->toBe([0, 0, 0]);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    cancelGuest($this, $id)->assertSessionHas('success');
    expect(parts())->toBe([0, 0, 0]);
    $this->artisan('canteen:check-money')->assertExitCode(0);
});

test('4.3 a walk-in', function () {
    makeBill($this->student, 0);
    walkIn($this, $this->lunch, ['plates' => 2]);
    cancelGuest($this, GuestMeal::sole()->id)->assertSessionHas('success', '2 plates of Lunch cancelled. ₹140 cash to give back.');
    expect(GuestMeal::sole())->cancelled_at->not->toBeNull()->and(parts())->toBe([0, 0, 0]);
    walkIn($this, $this->lunch, ['method' => 'upi']);
    cancelGuest($this, GuestMeal::latest('id')->value('id'))->assertSessionHas('success', '1 plate of Lunch cancelled. ₹70 UPI to give back.');
});

test('4.4 refusals', function () {
    cardGuest($this, $this->lunch);
    $id = GuestMeal::sole()->id;
    foreach ([null, '', 'ab', str_repeat('a', 201), ['abc']] as $reason) {
        cancelGuest($this, $id, $reason)->assertSessionHasErrors('reason');
    }
    cancelGuest($this, 999)->assertNotFound();
    $this->post('/student-attendance/guest/abc/cancel', ['reason' => 'Wrong'])->assertNotFound();
    cancelGuest($this, $id);
    cancelGuest($this, $id)->assertSessionHasErrors(['reason' => 'This was already cancelled.']);
    walkIn($this, $this->lunch);
    cancelGuest($this, GuestMeal::latest('id')->value('id'));
    cancelGuest($this, GuestMeal::latest('id')->value('id'))->assertSessionHasErrors('reason');
    expect(Payment::sole()->amount)->toBe(0);
});

test('4.5 a later payment leaves cancelled plates', function () {
    cardGuest($this, $this->lunch);
    cancelGuest($this, GuestMeal::sole()->id);
    markMeal($this, $this->lunch);
    payBillOf($this, 70);
    expect(GuestMeal::sole())->is_paid->toBe('0')->payment_history_id->toBeNull()->and(StudentAttendance::sole()->is_paid)->toBe('1');
});

test('4.6 cancel of an older payment', function () {
    markMeal($this, $this->lunch);
    payBillOf($this, 70);
    cardGuest($this, $this->lunch);
    cancelGuest($this, GuestMeal::sole()->id);
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'Wrong']);
    expect(parts())->toBe([70, 0, 0])->and(GuestMeal::sole()->cancelled_at)->not->toBeNull();
});

test('4.7 the own meal is deleted', function () {
    $entry = markMeal($this, $this->lunch, [], true);
    cardGuest($this, $this->lunch);
    $this->post('/student-attendance/delete', ['id' => $entry->id]);
    expect(GuestMeal::sole()->cancelled_at)->toBeNull()->and(parts())->toBe([70, 0, 0]);
});

test('4.8 a student who left or is deleted', function () {
    cardGuest($this, $this->lunch);
    cardGuest($this, $this->lunch);
    $this->student->forceFill(['left_on' => '2026-01-11'])->save();
    cancelGuest($this, GuestMeal::first()->id)->assertSessionHas('success');
    $this->student->delete();
    cancelGuest($this, GuestMeal::latest('id')->value('id'))->assertSessionHas('success');
    expect(parts())->toBe([0, 0, 0]);
});

test('4.9 an error changes nothing', function (string $type) {
    $type === 'card' ? cardGuest($this, $this->lunch) : walkIn($this, $this->lunch);
    ActivityLog::creating(fn () => throw new Exception('no'));
    cancelGuest($this, GuestMeal::sole()->id)->assertServerError();
    expect(GuestMeal::sole()->cancelled_at)->toBeNull()->and((int) Payment::sum('amount'))->toBe($type === 'card' ? 70 : 0);
})->with(['card', 'walk_in']);

test('access', function () {
    cardGuest($this, $this->lunch);
    $this->actingAs($this->student);
    cancelGuest($this, GuestMeal::sole()->id)->assertForbidden();
    auth()->logout();
    cancelGuest($this, GuestMeal::sole()->id)->assertRedirect('/login');
});

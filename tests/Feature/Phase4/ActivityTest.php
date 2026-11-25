<?php

use App\Models\ActivityLog;
use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => moneySetup($this));

function lastLog(): ActivityLog
{
    return ActivityLog::latest('id')->first();
}

function person(array $data = []): array
{
    return $data + ['name' => 'Ravi', 'last_name' => 'Shah', 'collage_name' => 'Test College', 'email' => 'ravi@example.com', 'canteen_id' => 'C2', 'phone_number' => '8888888888'];
}

test('5.1 money actions are recorded', function () {
    markMeal($this, $this->lunch);
    expect(lastLog())->action->toBe('attendance.marked')->user_id->toBe($this->admin->id)->student_id->toBe($this->student->id)
        ->user_name->toBe($this->admin->full_name)->summary->toBe('Lunch on 10 January 2026 marked for Asha Patel (₹70)');
    $this->post('/student-attendance/delete', ['id' => StudentAttendance::sole()->id]);
    expect(lastLog())->action->toBe('attendance.deleted')->student_id->toBe($this->student->id);

    markMeal($this, $this->dinner);
    payBillOf($this, 100);
    expect(lastLog())->action->toBe('payment.saved')->summary->toBe('₹100 by cash from Asha Patel');
    $this->post('/bill/' . PaymentHistory::sole()->id . '/cancel', ['reason' => 'wrong student']);
    expect(lastLog())->action->toBe('payment.cancelled')->summary->toBe('₹100 from Asha Patel cancelled: wrong student');
    expect(ActivityLog::count())->toBe(5);
});

test('5.1 5.3 student changes name the fields only', function () {
    $this->post('/user', person());
    $ravi = User::firstWhere('email', 'ravi@example.com');
    expect(lastLog())->action->toBe('student.added')->student_id->toBe($ravi->id)->summary->toBe('Ravi Shah added');

    $this->put("/user/{$ravi->id}", person(['phone_number' => '7777777777', 'email' => 'new@example.com', 'collage_name' => 'North']));
    expect(lastLog())->action->toBe('student.changed')->summary->toBe('Ravi Shah changed: college, email, phone number');
    $this->delete("/user/{$ravi->id}");
    expect(lastLog())->action->toBe('student.deleted')->summary->toBe('Ravi Shah deleted');

    expect(ActivityLog::all()->toJson())->not->toContain('7777777777')->not->toContain('8888888888')->not->toContain('@example.com');
});

test('5.1 meal and menu changes', function () {
    $this->post('/meal-price', ['name' => 'Tea', 'price' => 10]);
    expect(lastLog())->action->toBe('meal.added')->summary->toBe('Tea added (₹10)');
    $tea = MealPrice::firstWhere('slug', 'tea');
    $this->put("/meal-price/{$tea->id}", ['name' => 'Tea', 'price' => 12]);
    expect(lastLog())->action->toBe('meal.changed')->summary->toBe('Tea price changed from ₹10 to ₹12');
    $this->put("/meal-price/{$tea->id}", ['name' => 'Chai', 'price' => 12]);
    expect(lastLog()->summary)->toBe('Tea changed: name');
    $this->delete("/meal-price/{$tea->id}");
    expect(lastLog()->action)->toBe('meal.deleted');

    $this->post('/menus', ['name' => 'Curd', 'is_extra' => 'on', 'price' => 15]);
    expect(lastLog()->action)->toBe('menu.added');
    $curd = Menus::sole();
    $this->put("/menus/{$curd->id}", ['name' => 'Curd', 'is_extra' => 'on', 'price' => 20]);
    expect(lastLog())->action->toBe('menu.changed')->summary->toBe('Curd price changed from ₹15 to ₹20');
    $this->delete("/menus/{$curd->id}");
    expect(lastLog()->action)->toBe('menu.deleted');
});

test('5.2 nothing recorded for refused or unchanged saves', function () {
    $this->post('/user', person());
    $this->post('/menus', ['name' => 'Curd', 'is_extra' => 'on', 'price' => 15]);
    markMeal($this, $this->lunch);
    $before = ActivityLog::count();

    markMeal($this, $this->lunch);
    $this->post('/meal-price', ['name' => '']);
    $this->put("/meal-price/{$this->lunch->id}", ['name' => 'Lunch', 'price' => 70]);
    $this->put('/menus/' . Menus::sole()->id, ['name' => 'Curd', 'is_extra' => 'on', 'price' => 15]);
    $this->put('/user/' . User::firstWhere('email', 'ravi@example.com')->id, person(['middle_name' => '']));
    $this->delete("/meal-price/{$this->lunch->id}");

    expect(ActivityLog::count())->toBe($before);
});

test('5.4 page, search, filter and dates', function () {
    $this->travelTo('2026-01-05 10:00:00');
    ActivityLog::record('meal.added', null, 'Tea added');
    $this->travelTo('2026-01-06 23:50:00');
    ActivityLog::record('payment.saved', $this->student->id, '₹5 by cash 100%');
    $names = function (string $query): array {
        $rows = [];
        $this->get("/changes?$query")->assertOk()->assertInertia(function (Assert $page) use (&$rows) {
            $page->component('Activity/Index');
            $rows = array_column($page->toArray()['props']['changes']['data'], 'summary');
        });

        return $rows;
    };

    expect($names(''))->toBe(['₹5 by cash 100%', 'Tea added']);
    expect($names('search=tea'))->toBe(['Tea added'])->and($names('search=%25'))->toBe(['₹5 by cash 100%']);
    expect($names('type=payment'))->toBe(['₹5 by cash 100%']);
    expect($names('from=2026-01-06&to=2026-01-06'))->toBe(['₹5 by cash 100%'])->and($names('to=2026-01-05'))->toBe(['Tea added']);
    expect($names('type[]=x&from=bad&to[]=x&search[]=a&page=99&sort=x'))->toHaveCount(2);
});

test('5.5 read only, admins only', function () {
    foreach (['POST', 'PUT', 'DELETE'] as $method) {
        expect($this->call($method, '/changes')->status())->toBeIn([404, 405]);
    }
    $this->actingAs(makeStudent())->get('/changes')->assertForbidden();
    auth()->logout();
    $this->get('/changes')->assertRedirect('/login');
});

test('5.6 names survive deletion', function () {
    markMeal($this, $this->lunch);
    StudentAttendance::query()->delete();
    App\Models\Payment::query()->delete();
    $name = $this->admin->full_name;
    $this->student->forceDelete();
    $this->admin->forceDelete();

    expect(lastLog())->user_id->toBeNull()->student_id->toBeNull()->user_name->toBe($name)->summary->toContain('Asha Patel');
});

test('5.7 long summaries are cut', function () {
    ActivityLog::record('meal.added', null, str_repeat('₹', 400));
    expect(mb_strlen(lastLog()->summary))->toBe(255);
});

test('5.8 change and record are one', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('SECRET'));
    $this->put("/meal-price/{$this->lunch->id}", ['name' => 'Lunch', 'price' => 90])->assertStatus(500);
    expect($this->lunch->fresh()->price)->toEqual(70);
});

<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

function register($test, array $data = [])
{
    return $test->post('/register', $data + [
        'name' => 'Asha', 'last_name' => 'Patel', 'collage_name' => 'Test College',
        'email' => 'asha@example.com', 'phone_number' => '9999999999',
        'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
    ]);
}

test('registration page renders', function () {
    $this->get('/register')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
});

test('student registers', function () {
    register($this)->assertRedirect('/account')
        ->assertSessionHas('success', 'Registered. Collect your canteen ID at the counter.');

    $user = User::sole();
    $this->assertAuthenticatedAs($user);
    expect($user)->role->toBe('user')->canteen_id->toBeNull()->name->toBe('Asha')->last_name->toBe('Patel');
    expect(Hash::check('secret-pass', $user->password))->toBeTrue();
});

test('privileged fields are ignored', function () {
    register($this, ['role' => 'admin', 'canteen_id' => 'C9', 'profile_pic' => 'x']);

    expect(User::sole())->role->toBe('user')->canteen_id->toBeNull()->profile_pic->toBeNull();
});

test('invalid registration is rejected', function (array $data, array $fields) {
    register($this, $data)->assertSessionHasErrors($fields);
    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'empty' => [
        ['name' => '', 'collage_name' => '', 'email' => '', 'phone_number' => '', 'password' => ''],
        ['name', 'collage_name', 'email', 'phone_number', 'password'],
    ],
    'short phone' => [['phone_number' => '999999999'], ['phone_number']],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
    'different confirmation' => [['password_confirmation' => 'other-pass'], ['password']],
]);

test('email already used cannot be claimed', function (?string $deletedAt) {
    $existing = User::factory()->create(['role' => 'user', 'email' => 'asha@example.com', 'deleted_at' => $deletedAt]);
    $before = DB::table('users')->find($existing->id);

    register($this)->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(DB::table('users')->count())->toBe(1);
    expect(DB::table('users')->find($existing->id))->toEqual($before);
})->with(['active' => [null], 'deleted' => ['2026-01-01 00:00:00']]);

test('last name is optional', function () {
    register($this, ['last_name' => ''])->assertRedirect('/account');
    expect(User::sole()->last_name)->toBeNull();
});

test('admin can assign an id to a registered student', function () {
    register($this);
    $student = User::sole();

    $this->actingAs(User::factory()->create(['role' => 'admin']))->put("/user/{$student->id}", [
        'name' => 'Asha', 'last_name' => 'Patel', 'collage_name' => 'Test College',
        'email' => 'asha@example.com', 'phone_number' => '9999999999', 'canteen_id' => 'C9',
    ])->assertSessionHasNoErrors();

    expect($student->fresh()->canteen_id)->toBe('C9');
    $this->actingAs($student->fresh())->get('/account')
        ->assertInertia(fn (Assert $page) => $page->where('student.canteen_id', 'C9'));
});

test('registration is limited', function () {
    foreach (range(1, 5) as $attempt) {
        register($this, ['email' => 'bad'])->assertSessionHasErrors('email');
    }
    register($this)->assertTooManyRequests();
});

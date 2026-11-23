<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

const LOGIN_ERROR = ['email' => 'Email or password is incorrect.'];

function userWithRole(string $role, array $data = []): User
{
    return User::factory()->create($data + ['role' => $role, 'password' => 'secret-pass']);
}

function signIn($test, User $user, string $password = 'secret-pass', array $headers = [])
{
    return $test->post('/login', ['email' => $user->email, 'password' => $password], $headers);
}

test('login page renders', function () {
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

test('admin goes to dashboard', function () {
    signIn($this, userWithRole('admin'))->assertRedirect('/dashboard');
    $this->assertAuthenticated();
});

test('admin login over inertia is a normal redirect', function () {
    signIn($this, userWithRole('admin'), headers: ['X-Inertia' => 'true'])->assertRedirect('/dashboard');
});

test('inertia visits that end on a download do a full visit', function () {
    $url = '/student-attendance/download?from=2026-01-01&to=2026-01-31';

    $this->actingAs(userWithRole('admin'))->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
    ])->assertStatus(409)->assertHeader('X-Inertia-Location', url($url));
});

test('student goes to account', function () {
    signIn($this, userWithRole('user'))->assertRedirect('/account');
    $this->assertAuthenticated();
});

test('student with a stored admin url goes to account', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    signIn($this, userWithRole('user'))->assertRedirect('/account');
});

test('admin with a stored url returns to it', function () {
    $this->get('/user')->assertRedirect('/login');
    signIn($this, userWithRole('admin'))->assertRedirect('/user');
});

test('wrong password is rejected', function () {
    signIn($this, userWithRole('user'), 'wrong')->assertSessionHasErrors(LOGIN_ERROR);
    $this->assertGuest();
});

test('student without password or deleted cannot log in', function (array $data) {
    signIn($this, userWithRole('user', $data))->assertSessionHasErrors(LOGIN_ERROR);
    $this->assertGuest();
})->with([
    'no password' => [['password' => null]],
    'deleted' => [['deleted_at' => '2026-01-01 00:00:00']],
]);

test('sixth failed attempt is blocked', function () {
    $user = userWithRole('user');
    foreach (range(1, 5) as $attempt) {
        signIn($this, $user, 'wrong')->assertSessionHasErrors(LOGIN_ERROR);
    }

    signIn($this, $user)->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('students cannot use admin pages', function (string $method, string $url) {
    $this->actingAs(userWithRole('user'))->call($method, $url)->assertForbidden();
})->with([
    ['GET', '/dashboard'], ['GET', '/user'], ['GET', '/menus'], ['GET', '/meal-price'],
    ['GET', '/student-attendance'], ['GET', '/bill'],
    ['POST', '/user'], ['POST', '/student-attendance/store'], ['GET', '/bill/1/receipt'],
]);

test('signed in users skip login', function (string $role, string $url) {
    $this->actingAs(userWithRole($role))->get('/login')->assertRedirect($url);
})->with([['admin', '/dashboard'], ['user', '/account']]);

test('logout needs a post', function () {
    $this->actingAs(userWithRole('user'))->get('/logout')->assertMethodNotAllowed();
    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

test('account shows only the student details it needs', function () {
    $student = userWithRole('user', ['name' => 'Asha', 'middle_name' => null, 'last_name' => null, 'canteen_id' => 'C9']);

    $this->actingAs($student)->get('/account')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Account')->where('student', [
            'name' => 'Asha', 'collage_name' => $student->collage_name, 'canteen_id' => 'C9',
        ]));
});

test('account is only for students', function () {
    $this->get('/account')->assertRedirect('/login');
    $this->actingAs(userWithRole('admin'))->get('/account')->assertRedirect('/dashboard');
});

test('admin pages are not kept by the browser after logout', function () {
    $response = $this->actingAs(userWithRole('admin'))->get('/dashboard');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('signing out leaves with a full page load so back shows nothing private', function () {
    $this->actingAs(userWithRole('admin'))->post('/logout', [], ['X-Inertia' => 'true'])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', route('login'))->assertSessionHas('success');
    $this->assertGuest();
});

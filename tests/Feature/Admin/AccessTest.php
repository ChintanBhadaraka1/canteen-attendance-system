<?php

use Illuminate\Support\Facades\Route;

const OPEN_ROUTES = ['home', 'login', 'login.user', 'register', 'register.store', 'logout', 'account', 'storage.local', 'storage.local.upload'];

test('every other route is for admins only', function () {
    $student = makeStudent();
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (in_array($route->getName(), OPEN_ROUTES) || $route->uri() === 'up') {
            continue;
        }
        expect($route->gatherMiddleware())->toContain('admin');
        $url = preg_replace('/\{[^}]+\}/', '1', $route->uri());
        $this->actingAs($student)->call($route->methods()[0], $url)->assertForbidden();
        $checked++;
    }

    expect($checked)->toBeGreaterThan(25);
});

test('old endpoints are gone', function (string $method, string $url) {
    expect($this->actingAs(makeAdmin())->call($method, $url)->status())->toBeIn([404, 405]);
})->with([
    ['POST', '/user/list'], ['POST', '/menus/list'], ['POST', '/bill/list'], ['POST', '/bill/download'],
    ['POST', '/student-attendance/list'], ['POST', '/student-attendance/specific-user/list'],
    ['POST', '/student-attendance/direct'], ['POST', '/student-attendance/download'],
]);

<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the landing page shows real meal prices', function () {
    $this->seed();

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Landing')->has('meals', 3)
        ->where('meals.1.name', 'Lunch')->where('meals.1.price', 70));
});

test('the landing page works without meals', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('meals', 0));
});

<?php

use App\Models\MealPrice;
use App\Models\StudentAttendance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->actingAs(makeAdmin()));

function studentForm(array $data = []): array
{
    return $data + ['name' => 'Ravi', 'collage_name' => 'Test College', 'email' => 'ravi@example.com', 'canteen_id' => 'C2', 'phone_number' => '8888888888'];
}

test('list has students only, even one named admin', function () {
    makeStudent(['name' => 'admin']);

    $this->get('/user')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Students/Index')->has('students.data', 1)
        ->where('students.data.0.name', 'admin')->missing('students.data.0.password')->missing('students.data.0.phone_number'));
});

test('student search', function (string $search) {
    makeStudent(['name' => 'Asha', 'middle_name' => 'Raj', 'last_name' => 'Patel', 'email' => 'asha@example.com', 'canteen_id' => 'C77', 'collage_name' => 'North']);
    makeStudent(['name' => 'Zed', 'middle_name' => null, 'last_name' => null, 'email' => 'z@x.com', 'canteen_id' => '1', 'collage_name' => 'South']);

    $this->get('/user?' . http_build_query(['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page->has('students.data', 1)->where('students.data.0.name', 'Asha'));
})->with([' Asha ', 'Raj', 'Patel', 'asha@', 'C77', 'North']);

test('search treats special characters literally', function (string $search) {
    makeStudent(['name' => 'Asha', 'collage_name' => 'Plain']);
    makeStudent(['name' => 'Odd', 'collage_name' => 'A%B_C\\D!E']);

    $this->get('/user?' . http_build_query(['search' => $search]))
        ->assertInertia(fn (Assert $page) => $page->has('students.data', 1)->where('students.data.0.name', 'Odd'));
})->with(['%', '_', '\\', '!']);

test('student sorting', function (string $sort) {
    makeStudent(['name' => 'Aa', 'canteen_id' => 'A', 'collage_name' => 'Aa']);
    makeStudent(['name' => 'Bb', 'canteen_id' => 'B', 'collage_name' => 'Bb']);

    foreach (['asc' => 'Aa', 'desc' => 'Bb'] as $dir => $first) {
        $this->get("/user?sort=$sort&dir=$dir")->assertInertia(fn (Assert $page) => $page
            ->where('students.data.0.name', $first)->where('filters.sort', $sort)->where('filters.dir', $dir));
    }
})->with(['name', 'canteen_id', 'collage_name']);

test('student paging and bad parameters', function () {
    foreach (range(1, 11) as $n) {
        makeStudent();
    }

    $this->get('/user')->assertInertia(fn (Assert $page) => $page->has('students.data', 10));
    $this->get('/user?page=2')->assertInertia(fn (Assert $page) => $page->has('students.data', 1));
    $this->get('/user?page=99')->assertOk()->assertInertia(fn (Assert $page) => $page->has('students.data', 1));
    $this->get('/user?sort=password&dir=x')->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.sort', 'name'));
    $this->get('/user?search[]=a&sort[]=b&dir[]=c&page[]=d')->assertOk();
    $this->get('/user?search=' . str_repeat('a', 500))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.search', str_repeat('a', 100)));
});

test('student form pages', function () {
    $student = makeStudent(['canteen_id' => null]);

    $this->get('/user/create')->assertInertia(fn (Assert $page) => $page->component('Students/Form')->where('student', null));
    $this->get("/user/{$student->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('Students/Form')->where('student.id', $student->id)->where('student.canteen_id', null)->missing('student.password'));
});

test('profile shows month meals and the stored balance', function () {
    $student = makeStudent();
    $lunch = MealPrice::create(['name' => 'Lunch', 'slug' => 'lunch', 'price' => 70]);
    MealPrice::create(['name' => 'Tea', 'slug' => 'tea', 'price' => 5]);
    StudentAttendance::create(['student_id' => $student->id, 'meal_id' => $lunch->id, 'amount' => 70, 'date' => today()->toDateString()]);

    $this->get("/user/{$student->id}")->assertInertia(fn (Assert $page) => $page
        ->component('Students/Show')->where('meals.0.total', 1)->where('meals.1.total', 0)->where('bill', null));

    makeBill($student, 70, 20, 5);
    $this->get("/user/{$student->id}")->assertInertia(fn (Assert $page) => $page
        ->where('bill', ['amount' => 70, 'pending_amount' => 20, 'advance_amount' => 5]));
});

test('student is added and changed', function () {
    $this->post('/user', studentForm())->assertRedirect('/user')->assertSessionHas('success', 'Ravi added.');
    $ravi = User::firstWhere('email', 'ravi@example.com');

    $this->put("/user/{$ravi->id}", studentForm(['name' => 'Ravindra']))
        ->assertRedirect('/user')->assertSessionHas('success', 'Changes to Ravindra saved.');
    $this->post('/user', studentForm(['email' => 'x', 'phone_number' => '98765']))
        ->assertSessionHasErrors(['email', 'canteen_id', 'phone_number' => 'Enter all 10 digits.']);
});

test('photo is saved and replaced', function () {
    $public = storage_path('framework/testing/public-' . uniqid());
    $this->app->usePublicPath($public);

    try {
        $this->post('/user', studentForm(['profile_pic' => UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')]))->assertSessionHasNoErrors();
        $ravi = User::firstWhere('email', 'ravi@example.com');
        expect($ravi->profile_pic)->toBe('ravi_8888888888.jpg');
        expect(File::exists("$public/image/profile/ravi_8888888888.jpg"))->toBeTrue();

        $this->post("/user/{$ravi->id}", studentForm([
            '_method' => 'put', 'name' => 'Ravindra', 'profile_pic' => UploadedFile::fake()->create('b.png', 10, 'image/png'),
        ]))->assertSessionHasNoErrors();
        expect($ravi->fresh()->profile_pic)->toBe('ravindra_8888888888.png');
        expect(File::files("$public/image/profile"))->toHaveCount(1);
    } finally {
        File::deleteDirectory($public);
    }
});

test('student is soft deleted', function () {
    $student = makeStudent(['name' => 'Asha', 'middle_name' => null, 'last_name' => 'Patel']);

    $this->delete("/user/{$student->id}")->assertRedirect('/user')->assertSessionHas('success', 'Asha Patel deleted.');
    $this->assertSoftDeleted($student);
});

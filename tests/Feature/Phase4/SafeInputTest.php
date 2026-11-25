<?php

use App\Models\ActivityLog;
use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\StudentAttendance;
use App\Models\User;

beforeEach(fn () => moneySetup($this));

test('6.1 extra fields are ignored', function () {
    $evil = ['role' => 'admin', 'password' => 'hacked-pass', 'id' => 9999, 'deleted_at' => '2026-01-01'];
    $data = $evil + ['name' => 'Ravi', 'collage_name' => 'Test College', 'email' => 'ravi@example.com', 'canteen_id' => 'C2', 'phone_number' => '8888888888'];
    $this->post('/user', $data)->assertSessionHasNoErrors();
    $ravi = User::firstWhere('email', 'ravi@example.com');
    expect($ravi)->role->toBe('user')->password->toBeNull()->id->not->toBe(9999);

    $this->put("/user/{$ravi->id}", ['name' => 'Ravindra'] + $data)->assertSessionHasNoErrors();
    expect($ravi->fresh())->name->toBe('Ravindra')->role->toBe('user')->password->toBeNull()->deleted_at->toBeNull();
    expect((new User)->isFillable('role'))->toBeFalse();

    auth()->logout();
    $this->post('/register', ['name' => 'Nina', 'collage_name' => 'North', 'email' => 'nina@example.com', 'phone_number' => '8888888888', 'role' => 'admin', 'password' => 'long-password', 'password_confirmation' => 'long-password']);
    expect(User::firstWhere('email', 'nina@example.com')->role)->toBe('user');
});

test('6.2 the seeded admin can sign in', function () {
    auth()->logout();
    $this->seed();
    expect(User::firstWhere('email', 'canteen@admin.com')->role)->toBe('admin');
    $this->post('/login', ['email' => 'canteen@admin.com', 'password' => 'Canteen@admin123'])->assertRedirect('/dashboard');
});

test('6.3 prices are whole rupees', function (string $url) {
    foreach ([70.5, -1, 100001, 'x'] as $price) {
        $this->post($url, ['name' => 'Snack', 'price' => $price])->assertSessionHasErrors('price');
    }
    foreach ([0, 100000] as $price) {
        $this->post($url, ['name' => "Snack $price", 'price' => $price])->assertSessionHasNoErrors();
    }
})->with(['/meal-price', '/menus']);

test('6.4 a used meal cannot be deleted', function () {
    markMeal($this, $this->lunch);
    $this->delete("/meal-price/{$this->lunch->id}")->assertRedirect('/meal-price')
        ->assertSessionHas('error', 'Lunch cannot be deleted because attendance uses it.');
    expect(StudentAttendance::sole()->meal_id)->toBe($this->lunch->id);

    $this->delete("/meal-price/{$this->dinner->id}")->assertSessionHas('success');
    expect(MealPrice::find($this->dinner->id))->toBeNull();
});

test('6.5 csv text cannot become a formula', function (string $name, string $expected) {
    $this->student->update(['name' => $name, 'last_name' => null, 'canteen_id' => '=1']);
    $item = Menus::create(['name' => '=x', 'slug' => 'x', 'is_extra' => '1', 'price' => 15]);
    markMeal($this, $this->lunch, ['include_extra' => 'yes', 'extra_items' => [$item->id => 1]]);
    $csv = $this->get('/student-attendance/download?from=2026-01-01&to=2026-01-31')->streamedContent();
    $row = str_getcsv(explode("\n", $csv, 2)[1], ',', '"', '');

    expect($row[1])->toBe($expected)->and($row[2])->toBe("'=1")->and($row[5])->toBe('70.00')->and($row[6])->toBe("'=x x 1")->and($row[7])->toBe('15.00');
})->with([
    ['=1+1', "'=1+1"], ['+1', "'+1"], ['-1', "'-1"], ['@x', "'@x"], [' =1', "'=1"], ["\t=1", "'=1"], ['Asha', 'Asha'],
]);

test('6.6 one space between name parts', function (?string $middle, string $expected) {
    expect((new User)->forceFill(['name' => ' Asha ', 'middle_name' => $middle, 'last_name' => 'Patel'])->full_name)->toBe($expected);
})->with([['Raj', 'Asha Raj Patel'], [null, 'Asha Patel'], ['  ', 'Asha Patel']]);

test('6.7 exception text is not shown', function () {
    config(['app.debug' => false]);
    ActivityLog::creating(fn () => throw new RuntimeException('SECRET'));
    $this->post('/meal-price', ['name' => 'Tea', 'price' => 5])->assertStatus(500)->assertDontSee('SECRET');
});

<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

const HEAD = "first_name,middle_name,last_name,college,email,phone,canteen_id\n";

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs($this->admin = makeAdmin());
});

function line(int $n, array $data = []): string
{
    return implode(',', array_replace(["Name$n", '', 'Shah', 'North', "s$n@example.com", '9876543210', "K$n"], $data)) . "\n";
}

function upload($test, string $content, string $name = 'students.csv')
{
    return $test->post('/user/import', ['file' => UploadedFile::fake()->createWithContent($name, $content)]);
}

function preview($test): ?array
{
    $preview = null;
    $test->get('/user/import')->assertOk()->assertInertia(function (Assert $page) use (&$preview) {
        $preview = $page->component('Students/Import')->toArray()['props']['preview'];
    });

    return $preview;
}

function students(): int
{
    return User::where('role', 'user')->count();
}

function files(): array
{
    return Storage::disk('local')->files('imports');
}

test('5.1 5.2 upload, then confirm', function () {
    upload($this, HEAD . line(1) . line(2) . line(3))->assertRedirect('/user/import')->assertSessionHasNoErrors();
    expect(students())->toBe(0)->and(files())->toHaveCount(1)->and(files()[0])->not->toContain('students');
    expect(config('filesystems.disks.local.root'))->not->toStartWith(public_path());
    expect(preview($this))->good->toBe(3)->bad->toBe(0);

    $this->post('/user/import/confirm', ['count' => 3])->assertRedirect('/user')->assertSessionHas('success', '3 students added.');
    expect(students())->toBe(3)->and(files())->toBe([]);
    expect(User::firstWhere('canteen_id', 'K1'))->name->toBe('Name1')->middle_name->toBeNull()->last_name->toBe('Shah')
        ->collage_name->toBe('North')->phone_number->toBe('9876543210')->password->toBeNull()->role->toBe('user');
    expect(ActivityLog::sole())->action->toBe('student.imported')->summary->toBe('3 students added from a file');
});

test('5.3 correct rows can be saved alone', function () {
    upload($this, HEAD . line(1) . line(2, [3 => '']) . line(3));
    $preview = preview($this);
    expect($preview)->good->toBe(2)->bad->toBe(1);
    expect($preview['rows'][0])->line->toBe(3)->errors->toBe(['The college field is required.']);

    $this->post('/user/import/confirm', ['count' => 2]);
    expect(students())->toBe(2);
});

test('5.4 repeated and used values', function () {
    $old = makeStudent(['email' => 'S1@example.com', 'canteen_id' => 'k9']);
    makeStudent(['email' => 'gone@example.com', 'canteen_id' => 'GONE'])->delete();
    upload($this, HEAD . line(1) . line(2) . line(3, [4 => 'S2@Example.com']) . line(4, [6 => 'k2']) . line(5, [6 => 'K9']) . line(6, [4 => 'GONE@example.com']) . line(7, [6 => 'gone']));

    $errors = array_column(preview($this)['rows'], 'errors', 'line');
    expect($errors[2])->toBe(['This email is already used.'])->and($errors[3])->toBe([]);
    expect($errors[4])->toBe(['This email is already in line 3 of the file.'])->and($errors[5])->toBe(['This canteen ID is already in line 3 of the file.']);
    expect($errors[6])->toBe(['This canteen ID is already used.'])->and($errors[7])->toBe(['This email is already used.'])->and($errors[8])->toBe(['This canteen ID is already used.']);
    $this->post('/user/import/confirm', ['count' => 1]);
    expect($old->fresh()->updated_at->eq($old->updated_at))->toBeTrue()->and($old->fresh()->name)->toBe($old->name);
});

test('5.5 phone numbers', function (string $phone, string $error) {
    upload($this, HEAD . line(1, [5 => $phone]));

    expect(preview($this)['rows'][0]['errors'])->toBe([$error]);
})->with([
    ['9.87E+09', 'Excel changed this phone number. Format the column as Text and save again.'],
    ['987654321', 'Enter all 10 digits.'],
    ['+919876543210', 'Enter all 10 digits.'],
]);

test('5.6 file shapes', function (string $content) {
    upload($this, $content)->assertSessionHasNoErrors();

    expect(preview($this))->good->toBe(1)->bad->toBe(0);
    $this->post('/user/import/confirm', ['count' => 1]);
    expect(User::firstWhere('canteen_id', 'K1'))->name->toBe('Name1')->email->toBe('s1@example.com');
})->with([
    'bom' => ["\xEF\xBB\xBF" . HEAD . line(1)],
    'semicolon' => [str_replace(',', ';', HEAD . line(1))],
    'tab' => [str_replace(',', "\t", HEAD . line(1))],
    'order' => ["canteen_id,email,first_name,phone,college\nK1,s1@example.com,Name1,9876543210,North\n"],
    'upper case' => [strtoupper(HEAD) . line(1)],
    'blank rows' => [HEAD . "\n,,,,,,\n" . line(1) . "\n\n"],
    'windows lines' => [str_replace("\n", "\r\n", HEAD . line(1))],
    'spaces' => [HEAD . line(1, [0 => ' Name1 ', 6 => ' K1 '])],
    'quoted comma' => [HEAD . line(1, [3 => '"North, East"'])],
    'line break' => [HEAD . line(1, [3 => "\"North\nEast\""])],
]);

test('5.6 blank rows keep line numbers', function () {
    upload($this, HEAD . "\n" . line(1, [3 => '']));

    expect(preview($this)['rows'][0]['line'])->toBe(3);
});

test('5.7 encoding', function () {
    upload($this, HEAD . line(1, [0 => "Andr\xE9"]))->assertSessionHasErrors(['file' => 'This file uses an old text format. In Excel, choose Save as, CSV UTF-8.']);
    expect(files())->toBe([]);

    upload($this, HEAD . line(1, [0 => 'આશા']) . line(2, [0 => 'आशा']));
    $this->post('/user/import/confirm', ['count' => 2]);
    expect(User::where('role', 'user')->pluck('name')->all())->toBe(['આશા', 'आशा']);
});

test('5.8 lost zeros', function () {
    upload($this, HEAD . line(1, [6 => '1']) . line(2, [6 => '12']) . line(3, [6 => '123']));
    expect(array_column(preview($this)['rows'], 'warnings'))->toBe([
        ['Excel may have removed zeros from the front of IDs. Please check.'], ['Excel may have removed zeros from the front of IDs. Please check.'], [],
    ]);

    upload($this, HEAD . line(1, [6 => '001']) . line(2, [6 => '012']));
    expect(array_column(preview($this)['rows'], 'warnings'))->toBe([[], []])->and(files())->toHaveCount(1);
    $this->post('/user/import/confirm', ['count' => 2]);
    expect(User::where('role', 'user')->pluck('canteen_id')->all())->toBe(['001', '012']);
});

test('5.9 unusable files', function (string $content, string $name = 'students.csv') {
    upload($this, $content, $name)->assertSessionHasErrors('file');

    expect(students())->toBe(0)->and(files())->toBe([])->and(preview($this))->toBeNull();
})->with([
    'missing column' => ["first_name,email\nA,b@c.de\n"],
    'unknown column' => [str_replace('college', 'college,age', HEAD) . line(1)],
    'repeated column' => [str_replace('college', 'college,college', HEAD) . line(1)],
    'empty' => [''],
    'header only' => [HEAD],
    '501 rows' => [HEAD . implode('', array_map(line(...), range(1, 501)))],
    'over 1 MB' => [HEAD . str_repeat('x', 1100000)],
    'png' => ["\x89PNG\r\n\x1a\n" . str_repeat("\0", 50)],
    'wrong name' => [HEAD . line(1), 'students.xlsx'],
    '5.10 role' => [str_replace('college', 'college,role,password', HEAD) . line(1)],
]);

test('5.9 no file', function () {
    $this->post('/user/import')->assertSessionHasErrors('file');
    $this->post('/user/import', ['file' => 'x'])->assertSessionHasErrors('file');
});

test('5.11 the list changed after the preview', function () {
    upload($this, HEAD . line(1) . line(2));
    makeStudent(['email' => 's1@example.com']);
    $this->post('/user/import/confirm', ['count' => 2])->assertRedirect('/user/import')->assertSessionHas('error', 'The list has changed. Please check it again.');

    expect(students())->toBe(1)->and(preview($this))->good->toBe(1);
});

test('5.12 confirm twice, without a file, in another session', function () {
    $this->post('/user/import/confirm', ['count' => 1])->assertRedirect('/user/import')->assertSessionHas('error');
    upload($this, HEAD . line(1));
    $this->post('/user/import/confirm', ['count' => 1]);
    $this->post('/user/import/confirm', ['count' => 1])->assertRedirect('/user/import')->assertSessionHas('error');
    expect(students())->toBe(1);

    upload($this, HEAD . line(2));
    $this->flushSession();
    $this->actingAs(makeAdmin());
    expect(preview($this))->toBeNull();
    $this->post('/user/import/confirm', ['count' => 1])->assertSessionHas('error');
    expect(students())->toBe(1);
});

test('5.13 a failure halfway saves nobody', function () {
    upload($this, HEAD . line(1) . line(2));
    $n = 0;
    User::creating(function () use (&$n) {
        if (++$n === 2) {
            throw new RuntimeException('SECRET');
        }
    });
    $this->post('/user/import/confirm', ['count' => 2])->assertStatus(500);

    expect(students())->toBe(0);
});

test('5.14 formulas are only text', function () {
    moneySetup($this);
    upload($this, HEAD . line(1, [0 => '=1+1', 2 => '']));
    $this->post('/user/import/confirm', ['count' => 1]);
    $student = User::firstWhere('canteen_id', 'K1');
    expect($student->name)->toBe('=1+1');
    markMeal($this, $this->lunch, ['user_id' => $student->id]);

    expect($this->get('/student-attendance/download?from=2026-01-01&to=2026-01-31')->streamedContent())->toContain("'=1+1");
});

test('5.15 the number of queries does not grow', function () {
    $count = function (int $rows) {
        upload($this, HEAD . implode('', array_map(line(...), range(1, $rows))));
        DB::flushQueryLog();
        DB::enableQueryLog();
        preview($this);

        return count(DB::getQueryLog());
    };

    expect($count(10))->toBe($count(200));
});

test('5.16 import is not a student', function () {
    $this->get('/user/import')->assertInertia(fn (Assert $page) => $page->component('Students/Import'));
    $this->get('/user/' . makeStudent()->id)->assertInertia(fn (Assert $page) => $page->component('Students/Show'));
});

test('5.17 admins only', function () {
    $routes = [['GET', '/user/import'], ['POST', '/user/import'], ['DELETE', '/user/import'], ['GET', '/user/import/template'], ['POST', '/user/import/confirm']];
    $this->actingAs(makeStudent());
    foreach ($routes as [$method, $url]) {
        $this->call($method, $url)->assertForbidden();
    }
    auth()->logout();
    foreach ($routes as [$method, $url]) {
        $this->call($method, $url)->assertRedirect('/login');
    }
});

test('5.18 old files are removed', function () {
    $disk = Storage::disk('local');
    $disk->put('imports/old.csv', 'x');
    $disk->put('imports/fresh.csv', 'x');
    touch($disk->path('imports/old.csv'), now()->subDays(2)->timestamp);
    upload($this, HEAD . line(1));

    expect($disk->exists('imports/old.csv'))->toBeFalse()->and($disk->exists('imports/fresh.csv'))->toBeTrue()->and(files())->toHaveCount(2);
    $this->delete('/user/import')->assertRedirect('/user/import');
    expect(files())->toBe(['imports/fresh.csv'])->and(preview($this))->toBeNull();
});

test('template can be imported', function () {
    $content = $this->get('/user/import/template')->assertOk()->streamedContent();
    upload($this, $content)->assertSessionHasNoErrors();

    expect(preview($this))->good->toBe(1);
});

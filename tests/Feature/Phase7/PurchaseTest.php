<?php

use App\Models\ActivityLog;
use App\Models\StockEntry;
use App\Models\StockMovement;
use App\Models\StockPack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The type of a fake file comes from its name, of a real one from its content.
function realFile(string $name, string $content): UploadedFile
{
    file_put_contents($path = tempnam(sys_get_temp_dir(), 'bill'), $content);

    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(makeAdmin());
    $this->rice = stockItem('Rice');
    $this->oil = stockItem('Oil');
    $this->bag = StockPack::create(['stock_item_id' => $this->rice->id, 'name' => 'Bag', 'size' => 25000]);
    App\Models\MenuOption::create(['kind' => 'shop', 'name' => 'Ramesh']);
    App\Models\MenuOption::create(['kind' => 'shop', 'name' => 'Suresh']);
});

test('4.1 packs, grams, discount and charges', function () {
    buy($this, [[$this->rice, '2', '2500', "pack:{$this->bag->id}"], [$this->oil, '500', '60', 'g']], ['discount' => '10', 'charges' => '50', 'shop_id' => optionId('Ramesh')])
        ->assertSessionHasNoErrors();
    $entry = StockEntry::sole();
    expect(stockOf($this->rice))->toBe([50000, 250000])->and(stockOf($this->oil))->toBe([500, 6000])->and($entry->total)->toBe(260000)
        ->and($entry->shop_name)->toBe('Ramesh')->and(StockMovement::first()->entered)->toBe('2 Bag × 25 kg')
        ->and(ActivityLog::where('action', 'stock.purchase')->sole()->summary)->toContain('₹2,600.00');
    $this->get("/purchases/{$entry->id}")->assertInertia(fn ($page) => $page->where('purchase.total', 260000)->where('rows.0.rate', 5000)->where('rows.1.cost', 6000));
});

test('4.2 paper total', function ($paper, $ok) {
    $response = buy($this, [[$this->rice, '1', '1250']], ['paper_total' => $paper]);
    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors(['paper_total' => 'The rows add up to ₹1,250.00 but the paper bill says ₹1,200.00.']);
    expect(StockEntry::count())->toBe($ok ? 1 : 0);
})->with([['1250', true], ['1200', false], ['', true]]);

test('4.3 bad purchases', function ($rows, $data, $field) {
    $rows = array_map(fn ($row) => is_array($row) ? $row + ['item' => $this->rice->id, 'quantity' => '1', 'unit' => 'kg', 'amount' => '10'] : $row, $rows);
    buy($this, [], ['rows' => $rows] + $data)->assertSessionHasErrors($field);
    expect(StockEntry::count())->toBe(0)->and(stockOf($this->rice))->toBe([0, 0]);
})->with([
    'discount' => [[[]], ['discount' => '10.01'], 'discount'],
    'minus' => [[[], ['amount' => '-1']], [], ['rows.1.amount' => 'Row 2: Enter an amount, for example 42.50.']],
    'decimals' => [[['amount' => '1.234']], [], 'rows.0.amount'],
    'empty amount' => [[[], ['amount' => '']], [], ['rows.1.amount' => 'Row 2: fill in this box.']],
    'zero' => [[['quantity' => '0']], [], 'rows.0.quantity'],
    'no rows' => [[], [], 'rows'],
    '101 rows' => [array_fill(0, 101, []), [], 'rows'],
    'unknown item' => [[['item' => 999]], [], ['rows.0.item' => 'Row 1: Choose an item from the list.']],
    'item list' => [[['item' => ['x']]], [], 'rows.0.item'],
    'unit list' => [[['unit' => ['x']]], [], 'rows.0.unit'],
    'method' => [[[]], ['method' => 'credit'], 'method'],
    'method list' => [[[]], ['method' => ['cash']], 'method'],
    'future' => [[[]], ['date' => '2099-01-01'], 'date'],
    'old' => [[[]], ['date' => '2020-01-01'], 'date'],
    'discount text' => [[[]], ['discount' => 'x'], 'discount'],
    'shop' => [[[]], ['shop_id' => 1], 'shop_id'],
    'token' => [[[]], ['token' => 'x'], 'token'],
    'text keys' => [['a' => []], [], 'rows'],
]);

test('4.3 a pack of another item', function () {
    buy($this, [[$this->oil, '1', '10', "pack:{$this->bag->id}"]])->assertSessionHasErrors(['rows.0.unit' => 'Row 1: Choose a unit from the list.']);
});

test('4.4 nothing optional', function () {
    buy($this, [[$this->rice, '1', '10']])->assertSessionHasNoErrors();
    expect(StockEntry::sole()->only('shop_id', 'shop_name', 'bill_number', 'bill_file', 'discount', 'charges'))
        ->toBe(['shop_id' => null, 'shop_name' => null, 'bill_number' => null, 'bill_file' => null, 'discount' => 0, 'charges' => 0]);
});

test('4.5 the same item twice', function () {
    buy($this, [[$this->rice, '1', '10'], [$this->rice, '2', '30']]);
    expect(StockMovement::pluck('after_quantity')->all())->toBe([1000, 3000])->and(stockOf($this->rice))->toBe([3000, 4000]);
});

test('4.6 bill number', function () {
    $again = fn (array $data) => buy($this, [[$this->rice, '1', '10']], $data + ['bill_number' => 'B7']);
    $ramesh = ['shop_id' => optionId('Ramesh')];
    $again($ramesh)->assertSessionHasNoErrors();
    $again($ramesh)->assertSessionHasErrors('different_bill');
    expect(StockEntry::count())->toBe(1);
    $again($ramesh + ['different_bill' => '1'])->assertSessionHasNoErrors();
    $again(['shop_id' => optionId('Suresh')])->assertSessionHasNoErrors();
    $again([])->assertSessionHasNoErrors();
    $again([])->assertSessionHasNoErrors();
    StockEntry::where('shop_id', optionId('Ramesh'))->pluck('id')->each(fn ($id) => cancelStock($this, $id));
    $again($ramesh)->assertSessionHasNoErrors();
});

test('4.7 photo', function ($file, $ok) {
    buy($this, [[$this->rice, '1', '10']], ['bill' => $file()]);
    expect(StockEntry::count())->toBe($ok ? 1 : 0)->and(Storage::disk('local')->allFiles())->toHaveCount($ok ? 1 : 0);
    if ($ok) {
        expect(StockEntry::sole()->bill_file)->toStartWith('bills/')->not->toContain('paper');
    }
})->with([
    'jpg' => [fn () => UploadedFile::fake()->image('paper.jpg'), true],
    'pdf' => [fn () => realFile('paper.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\n"), true],
    'php' => [fn () => realFile('paper.jpg', '<?php echo 1;'), false],
    '6 MB' => [fn () => UploadedFile::fake()->image('paper.jpg')->size(6000), false],
    'svg' => [fn () => realFile('paper.jpg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), false],
    'text' => [fn () => 'x', false],
]);

test('4.8 same token with photos', function () {
    $data = ['token' => token()];
    buy($this, [[$this->rice, '1', '10']], $data + ['bill' => UploadedFile::fake()->image('a.jpg')]);
    buy($this, [[$this->rice, '1', '10']], $data + ['bill' => UploadedFile::fake()->image('b.jpg')]);
    expect(StockEntry::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toBe([StockEntry::sole()->bill_file]);
});

test('4.9 bill route', function () {
    buy($this, [[$this->rice, '1', '10']], ['bill' => UploadedFile::fake()->image('a.jpg')]);
    $entry = StockEntry::sole();
    $this->get("/purchases/{$entry->id}/bill")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'image/jpeg');
    cancelStock($this, $entry->id);
    $this->get("/purchases/{$entry->id}/bill")->assertOk();
    buy($this, [[$this->rice, '1', '10']]);
    $this->get('/purchases/' . lastEntry()->id . '/bill')->assertNotFound();
    Storage::disk('local')->delete($entry->bill_file);
    $this->get("/purchases/{$entry->id}/bill")->assertNotFound();
    $this->get('/purchases/999/bill')->assertNotFound();
    $this->actingAs(makeStudent())->get("/purchases/{$entry->id}/bill")->assertForbidden();
    auth()->logout();
    $this->get("/purchases/{$entry->id}/bill")->assertRedirect('/login');
});

test('4.9 a PDF is downloaded', function () {
    buy($this, [[$this->rice, '1', '10']], ['bill' => realFile('a.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\n")]);
    expect($this->get('/purchases/' . StockEntry::sole()->id . '/bill')->assertOk()->headers->get('Content-Disposition'))->toContain('attachment');
});

test('4.10 pack changed later', function () {
    buy($this, [[$this->rice, '2', '10', "pack:{$this->bag->id}"]]);
    $this->patch("/stock/packs/{$this->bag->id}/active", ['active' => false]);
    $this->post("/stock/{$this->rice->id}/packs", ['name' => 'Bag', 'size' => '50']);
    $this->get('/purchases/' . StockEntry::sole()->id)->assertInertia(fn ($page) => $page->where('rows.0.entered', '2 Bag × 25 kg'));
});

test('4.11 buy again', function () {
    $dal = stockItem('Dal');
    buy($this, [[$this->rice, '2', '10', "pack:{$this->bag->id}"], [$this->oil, '500', '5', 'g'], [$dal, '1', '5']], ['shop_id' => optionId('Ramesh'), 'bill_number' => 'B7']);
    $dal->update(['active' => false]);
    $this->bag->update(['active' => false]);
    $this->get('/purchases/create?from=' . StockEntry::sole()->id)->assertInertia(fn ($page) => $page
        ->where('rows', [['item' => $this->oil->id, 'quantity' => '500', 'unit' => 'g']])->where('shop', optionId('Ramesh'))->where('gone', ['Rice (2 Bag × 25 kg)', 'Dal'])->missing('bill_number'));
    $this->get('/purchases/create?from=999')->assertInertia(fn ($page) => $page->where('rows', [])->where('shop', null));
    $this->get('/purchases/create?from[]=1')->assertOk();
});

test('4.12 shop renamed or turned off', function () {
    buy($this, [[$this->rice, '1', '10']], ['shop_id' => $id = optionId('Ramesh')]);
    $this->put("/settings/options/$id", ['name' => 'New name']);
    $this->put("/settings/options/$id", ['active' => false]);
    $this->get('/purchases')->assertInertia(fn ($page) => $page->where('purchases.data.0.shop_name', 'Ramesh'));
    buy($this, [[$this->rice, '1', '10']], ['shop_id' => $id])->assertSessionHasErrors('shop_id');
});

test('4.13 filters', function () {
    buy($this, [[$this->rice, '1', '10']], ['shop_id' => optionId('Ramesh'), 'date' => today()->subDays(3)->toDateString()]);
    buy($this, [[$this->oil, '1', '20']], ['shop_id' => optionId('Suresh')]);
    buy($this, [[$this->rice, '1', '30'], [$this->rice, '1', '1']]);
    cancelStock($this, lastEntry()->id);
    $totals = fn ($query) => collect($this->get("/purchases?$query")->assertOk()->inertiaProps('purchases')['data'])->pluck('total')->all();
    expect($totals(''))->toBe([3100, 2000, 1000])->and($totals('to=' . today()->subDay()->toDateString()))->toBe([1000])->and($totals('from=' . today()->toDateString()))->toBe([3100, 2000])
        ->and($totals('shop=' . optionId('Suresh')))->toBe([2000])->and($totals("item={$this->rice->id}"))->toBe([3100, 1000])
        ->and($totals('show=cancelled'))->toBe([3100])->and($totals('show=saved'))->toBe([2000, 1000])->and($totals('search=rame'))->toBe([1000])
        ->and($totals('shop[]=1&item=x&from=x&show=x'))->toBe([3100, 2000, 1000]);
});

test('4.14 file removed after a failure', function () {
    ActivityLog::creating(fn () => throw new RuntimeException('log'));
    buy($this, [[$this->rice, '1', '10']], ['bill' => UploadedFile::fake()->image('a.jpg')])->assertStatus(500);
    expect(StockEntry::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('4.14 file removed after a refusal', function () {
    buy($this, [[$this->rice, '1', '10']], ['paper_total' => '1', 'bill' => UploadedFile::fake()->image('a.jpg')])->assertSessionHasErrors('paper_total');
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

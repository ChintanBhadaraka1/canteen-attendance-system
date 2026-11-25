<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\MenuOption;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\SpecialPrice;
use App\Models\Staff;
use App\Models\StockItem;
use App\Models\StockPack;
use App\Models\StudentAttendance;
use App\Models\StudentLeave;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CashService;
use App\Services\ExpenseService;
use App\Services\MenuPlanner;
use App\Services\SalaryService;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

// Local test data for every screen. Lives each day through the services, so bills, stock and cash always add up.
class DemoSeeder extends Seeder
{
    // name => [category, unit, low level, opening quantity, rupees per unit, used a day, bought a week]
    private const STOCK = [
        'Rice' => ['Grain', 'kg', 20, 60, 55, 6, 50],
        'Wheat flour' => ['Grain', 'kg', 15, 50, 40, 5, 40],
        'Tuvar dal' => ['Grain', 'kg', 8, 20, 140, 2, 15],
        'Potato' => ['Vegetable', 'kg', 10, 25, 30, 4, 30],
        'Onion' => ['Vegetable', 'kg', 10, 25, 35, 3, 22],
        'Milk' => ['Dairy', 'litre', 5, 20, 60, 3, 22],
        'Eggless bread' => ['Other', 'piece', 10, 30, 45, 4, 30],
        'Sunflower oil' => ['Oil', 'litre', 8, 30, 130, 1, 8],
        'Gas cylinder' => ['Gas', 'piece', 1, 4, 1800, 0, 1],
        'Ghee' => ['Dairy', 'kg', 3, 5, 600, 1, 0], // Never bought again: runs out.
        'Turmeric' => ['Spice', 'kg', 2, 2, 220, 0, 0], // At its low level.
        'Paper napkins' => [null, 'piece', null, null, 0, 0, 0], // No category, level or stock.
    ];

    // name => [category, food type, extra price]
    private const MENU = [
        'Dal fry' => ['Dal', 'Veg', null], 'Jain dal' => ['Dal', 'Jain', null], 'Aloo sabzi' => ['Sabzi', 'Veg', null],
        'Bhindi masala' => ['Sabzi', 'Veg', null], 'Paneer sabzi' => ['Sabzi', 'Veg', 60], 'Jain kela sabzi' => ['Sabzi', 'Jain', null],
        'Chapati' => ['Roti', 'Jain', 8], 'Thepla' => ['Roti', 'Veg', 12], 'Steamed rice' => ['Rice', 'Jain', null],
        'Jeera rice' => ['Rice', 'Veg', 30], 'Gulab jamun' => ['Sweet', 'Veg', 20], 'Chaas' => ['Drink', 'Jain', 15],
        'Tea' => ['Drink', 'Veg', 10], 'Poha' => ['Other', 'Veg', null], 'House special' => [null, null, 40],
    ];

    private BillingService $billing;

    private StockService $stock;

    public function run(int $days = 85): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is not for production.');
        }
        if (User::withTrashed()->where('role', 'user')->exists() || StudentAttendance::exists() || PaymentHistory::exists() || StockItem::exists()
            || Staff::exists() || Menus::exists() || GuestMeal::exists()) {
            throw new RuntimeException('This database already has records. Use an empty one; nothing was changed.');
        }
        $this->call(DatabaseSeeder::class);
        $this->billing = app(BillingService::class);
        $this->stock = app(StockService::class);
        Auth::setUser(User::where('email', 'canteen@admin.com')->firstOrFail());
        fake()->seed(2025);

        $frozen = Carbon::getTestNow();
        $now = now();
        $start = $now->copy()->startOfDay()->subDays($days);
        try {
            $this->clock($start, 7);
            $students = $this->students();
            $meals = $this->meals($now);
            $this->oldRecords($students[0], $meals['lunch']);
            $this->menu($meals, $now);
            $items = $this->stockItems();
            $staff = $this->staff($start);
            AppSetting::whereKey(['module_staff', 'module_cash'])->update(['value' => '1']);

            for ($day = 0; $day <= $days; $day++) {
                $date = $start->copy()->addDays($day);
                $today = $day === $days;
                $today ? Carbon::setTestNow($now) : $this->clock($date, 12);

                $this->peopleChange($students, $day, $days);
                $this->takePayments($students, $date);
                $this->eat($students, $meals, $date->toDateString(), $day, $today);
                $this->guests($students, $meals['lunch'], $date->toDateString(), $day, $today);
                $this->kitchen($items, $date->toDateString(), $day, $today);
                $this->spend($date->toDateString(), $day, $today);
                $this->salaries($staff, $date, $day);
                if (! $today) {
                    $this->clock($date, 21);
                    $this->cash($date->toDateString(), $day);
                }
            }
            $this->corrections();
        } finally {
            Carbon::setTestNow($frozen);
            Auth::forgetUser();
            fake()->seed();
        }
        $this->command?->info('Demo data ready. Admin: see UserSeeder. Student: student1@example.com / password');
    }

    private function students(): Collection
    {
        $faker = fake('en_IN');
        $password = Hash::make('password');
        $colleges = ['LD Engineering', 'Nirma University', 'HL Commerce', 'St Xavier College'];

        return collect(range(1, 45))->map(function ($n) use ($faker, $password, $colleges) {
            $student = User::factory()->student()->create([
                'name' => $faker->firstName(), 'middle_name' => $n % 3 ? $faker->firstName() : null, 'last_name' => $n === 44 ? null : $faker->lastName(),
                'email' => "student$n@example.com", 'password' => $password, 'collage_name' => $colleges[$n % 4],
                // The last one registered online and has no ID yet.
                'canteen_id' => $n === 45 ? null : 'C' . (100 + $n),
            ]);
            ActivityLog::record('student.added', $student->id, "{$student->full_name} added");

            return $student;
        });
    }

    private function meals(Carbon $now): array
    {
        foreach (['break-fast' => ['07:30', '09:30'], 'lunch' => ['12:00', '14:30'], 'dinner' => ['19:00', '21:30']] as $slug => [$from, $to]) {
            MealPrice::where('slug', $slug)->update(['serve_from' => "$from:00", 'serve_to' => "$to:00"]);
        }
        MealPrice::create(['name' => 'Evening snack', 'slug' => 'evening-snack', 'price' => 25, 'position' => 9]);
        $meals = MealPrice::get()->keyBy('slug')->all();

        // One over, one on now, one to come.
        foreach ([['lunch', -20, -18, 90, 'Festival thali'], ['dinner', -1, 2, 120, 'Special dinner week'], ['lunch', 5, 7, 60, 'Exam discount']] as [$slug, $from, $to, $price, $reason]) {
            SpecialPrice::create(['meal_id' => $meals[$slug]->id, 'from_date' => $now->copy()->addDays($from)->toDateString(),
                'to_date' => $now->copy()->addDays($to)->toDateString(), 'price' => $price, 'reason' => $reason]);
        }

        return $meals;
    }

    // Kept the way records were saved before payments were linked to meals.
    private function oldRecords(User $student, MealPrice $lunch): void
    {
        $day = today()->subDays(10);
        $extra = Menus::create(['name' => 'Papad', 'slug' => 'papad', 'price' => 5, 'is_extra' => '1']);
        StudentAttendance::create(['student_id' => $student->id, 'meal_id' => $lunch->id, 'amount' => 60, 'extra_amount' => 5,
            'extra_meal_id' => json_encode([$extra->id]), 'date' => $day->toDateString(), 'is_paid' => '1']);
        Payment::create(['student_id' => $student->id]);
        PaymentHistory::create(['student_id' => $student->id, 'amount' => 65, 'payment_date' => $day->toDateString(), 'type' => 'cash', 'month_name' => $day->format('F')]);
    }

    private function menu(array $meals, Carbon $now): void
    {
        $ids = [];
        foreach (self::MENU as $name => [$category, $food, $price]) {
            $ids[$name] = Menus::create([
                'name' => $name, 'slug' => Str::slug($name), 'price' => $price, 'is_extra' => $price ? '1' : '0',
                'category_id' => $this->option('category', $category), 'food_type_id' => $this->option('food_type', $food),
                'description' => fake()->optional()->sentence(6),
            ])->id;
        }
        Menus::create(['name' => 'Mango ras', 'slug' => 'mango-ras', 'price' => 50, 'is_extra' => '1', 'available' => false,
            'category_id' => $this->option('category', 'Sweet'), 'description' => 'Only in summer']);

        $planner = app(MenuPlanner::class);
        $pick = fn (string ...$names) => array_map(fn ($name) => $ids[$name], $names);
        $from = today()->toDateString();
        $planner->save($meals['break-fast']->id, $from, 'weekly', range(1, 7), null, $pick('Poha', 'Thepla', 'Tea'), false, null);
        $planner->save($meals['lunch']->id, $from, 'weekly', [1, 3, 5], null, $pick('Dal fry', 'Aloo sabzi', 'Chapati', 'Steamed rice', 'Chaas'), false, null);
        $planner->save($meals['lunch']->id, $from, 'weekly', [2, 4, 6, 7], null, $pick('Jain dal', 'Jain kela sabzi', 'Chapati', 'Jeera rice'), false, 'Jain friendly');
        $planner->save($meals['dinner']->id, $from, 'weekly', range(1, 6), null, $pick('Bhindi masala', 'Chapati', 'Steamed rice'), false, null);
        $planner->save($meals['dinner']->id, $from, 'weekly', [7], null, [], true, 'Closed on Sunday evening');
        $planner->save($meals['evening-snack']->id, $from, 'weekly', range(1, 5), today()->addDays(13)->toDateString(), $pick('Tea'), false, null);
        $planner->save($meals['lunch']->id, $now->copy()->addDays(6)->toDateString(), 'once', [], null, $pick('Paneer sabzi', 'Gulab jamun', 'Chapati'), false, 'Feast');
        $planner->save($meals['lunch']->id, $now->copy()->addDays(9)->toDateString(), 'once', [], null, [], true, 'Holiday');
    }

    private function stockItems(): Collection
    {
        foreach (['City Grocers' => true, 'Fresh Vegetables' => true, 'Old Dairy' => false] as $name => $active) {
            MenuOption::create(['kind' => 'shop', 'name' => $name, 'active' => $active]);
        }
        $rows = [];
        foreach (self::STOCK as $name => [$category, $unit, $low, $quantity, $rate]) {
            $rows[] = ['name' => $name, 'category_id' => $this->option('stock_category', $category), 'unit' => $unit, 'low_level' => $low === null ? null : $low * 1000,
                'quantity' => $quantity === null ? null : (string) $quantity, 'amount' => $quantity === null ? null : (string) ($quantity * $rate)];
        }
        $items = $this->stock->addItems(['date' => today()->toDateString(), 'token' => $this->token()], $rows)->keyBy('name')->toBase();
        StockPack::create(['stock_item_id' => $items['Rice']->id, 'name' => 'Bag', 'size' => 25000]);
        StockPack::create(['stock_item_id' => $items['Sunflower oil']->id, 'name' => 'Can', 'size' => 15000, 'active' => false]);
        $items['Paper napkins']->update(['active' => false]);

        return $items;
    }

    private function staff(Carbon $start): Collection
    {
        $joined = $start->copy()->startOfMonth()->subMonth()->toDateString();

        return collect(['Cook' => 18000, 'Helper' => 11000, 'Cleaner' => 9000, 'Other' => 10000])->map(fn ($salary, $job) => app(SalaryService::class)->addStaff([
            'name' => fake('en_IN')->name(), 'phone' => $job === 'Other' ? null : fake()->numerify('98########'),
            'job_id' => $this->option('staff_job', $job), 'note' => fake()->optional()->sentence(4),
        ], $joined, $salary * 100));
    }

    private function peopleChange(Collection $students, int $day, int $days): void
    {
        $status = function (User $student, ?string $date, string $action, string $text) {
            $student->forceFill(['left_on' => $date])->save();
            ActivityLog::record($action, $student->id, "{$student->full_name} $text");
        };
        if ($day === 20) {
            $status($students[40], today()->toDateString(), 'student.left', 'marked as left');
            $status($students[41], today()->toDateString(), 'student.left', 'marked as left');
        }
        if ($day === 28) {
            $status($students[41], null, 'student.returned', 'marked as active');
        }
        if ($day === 30) {
            $students[42]->delete();
            ActivityLog::record('student.deleted', $students[42]->id, "{$students[42]->full_name} deleted");
        }
        // Past, current and coming leave.
        if ($day === 10 || $day === $days - 1) {
            foreach ($day === 10 ? [[6, 0, 4, 'Family function']] : [[7, 0, 5, 'Sick'], [8, 7, 12, null]] as [$key, $from, $to, $reason]) {
                $leave = StudentLeave::create(['student_id' => $students[$key]->id, 'from_date' => today()->addDays($from)->toDateString(),
                    'to_date' => today()->addDays($to)->toDateString(), 'reason' => $reason]);
                ActivityLog::record('student.leave_added', $students[$key]->id, "{$students[$key]->full_name} away from {$leave->from_date} to {$leave->to_date}");
            }
        }
    }

    // Some pay all, some a part, some too much, some never.
    private function takePayments(Collection $students, Carbon $date): void
    {
        foreach ($students as $key => $student) {
            if ($date->day !== 1 + $key % 8 || $key % 6 === 5 || $student->trashed()) {
                continue;
            }
            $bill = Payment::where('student_id', $student->id)->first();
            if (! $bill || $bill->balance <= 0) {
                continue;
            }
            $due = $bill->balance;
            $amount = match ($key % 6) {
                3 => max(1, intdiv($due, 2)),
                4 => $due + 500,
                default => $due,
            };
            $this->billing->takePayment($bill->id, $amount, $key % 2 ? 'upi' : 'cash', $date->copy()->startOfMonth()->subMonth()->format('F'), $due);
        }
    }

    private function eat(Collection $students, array $meals, string $date, int $day, bool $today): void
    {
        $extras = Menus::extras()->pluck('id');
        $chance = ['break-fast' => 45, 'lunch' => 75, 'dinner' => 60, 'evening-snack' => 30];
        $away = StudentLeave::during($date)->pluck('student_id');
        // The last two never eat and have no bill.
        $eaters = $students->take(43)->filter(fn ($student) => ! $student->trashed() && $student->left_on === null && ! $away->contains($student->id));
        foreach ($meals as $slug => $meal) {
            if ($slug === 'evening-snack' && $day === 14) {
                $meal->update(['active' => false]);
            }
            if (! $meal->active || ($slug === 'dinner' && ($today || Carbon::parse($date)->isSunday()))) {
                continue;
            }
            [$single, $bulk] = $eaters->filter(fn () => fake()->boolean($chance[$slug]))->partition(fn () => fake()->boolean(12));
            foreach ($single as $student) {
                $chosen = $extras->random(fake()->numberBetween(0, 2));
                $this->billing->markAttendance($student->id, $meal->id, $date, $chosen->mapWithKeys(fn ($id) => [$id => fake()->numberBetween(1, 3)])->all());
            }
            $save = fn () => $this->billing->markMany($bulk->pluck('id')->all(), $meal->id, $date, $meal->priceOn($date))['batch'];
            $batch = $save();
            if ($slug === 'lunch' && $day === 5 && $batch) {
                $this->billing->undoBatch($batch->id);
                $save();
            }
        }
    }

    private function guests(Collection $students, MealPrice $meal, string $date, int $day, bool $today): void
    {
        $extra = Menus::extras()->firstOrFail();
        foreach (['card', 'walk_in'] as $type) {
            if (! $today && ! fake()->boolean(35)) {
                continue;
            }
            $plates = fake()->numberBetween(1, 4);
            $quantity = fake()->numberBetween(0, 2);
            $data = ['meal_id' => $meal->id, 'date' => $date, 'plates' => $plates, 'extras' => [$extra->id => $quantity],
                'expected_total' => $plates * $meal->priceOn($date) + $quantity * (int) $extra->price,
                'guest_name' => fake()->boolean() ? fake('en_IN')->firstName() : null, 'token' => $this->token()];
            $type === 'card' ? $this->billing->addCardGuest($students[$day % 30]->id, $data)
                : $this->billing->addWalkIn($data + ['method' => fake()->randomElement(['cash', 'upi'])]);
        }
    }

    private function kitchen(Collection $items, string $date, int $day, bool $today): void
    {
        $save = fn (string $type, array $rows, array $more = []) => $this->stock->save(['type' => $type, 'date' => $date, 'token' => $this->token()] + $more, $rows);
        if ($day % 7 === 6 || $today) {
            $rows = [];
            foreach (array_filter(self::STOCK, fn ($row) => $row[6] > 0) as $name => $row) {
                $bags = $name === 'Rice';
                $rows[] = ['item' => $items[$name]->id, 'quantity' => (string) ($bags ? $row[6] / 25 : $row[6]),
                    'unit' => $bags ? 'pack:' . StockPack::where('stock_item_id', $items[$name]->id)->value('id') : $row[1],
                    'amount' => (string) round($row[6] * $row[4] * fake()->numberBetween(92, 110) / 100)];
            }
            $buy = fn (array $rows, int $n, string $bill) => $save('purchase', $rows, [
                'shop_id' => $n % 3 ? $this->option('shop', $n % 2 ? 'City Grocers' : 'Fresh Vegetables') : null, 'bill_number' => $n % 3 ? $bill : null,
                'method' => ['cash', 'upi', 'bank'][$n % 3], 'discount' => $n % 2 ? 5000 : 0, 'charges' => $n % 2 ? 0 : 2500,
                'paper_total' => null, 'different_bill' => false, 'note' => $n % 2 ? 'Weekly buying' : null]);
            foreach (array_chunk($rows, 3) as $n => $part) {
                $buy($part, $n, "B-$day-$n");
            }
            if ($day === 13) {
                $this->stock->cancel($buy([$rows[0]], 1, "B-$day-copy")->id, 'Entered twice');
            }
        }

        $use = [];
        foreach ($items as $name => $item) {
            $quantity = min(self::STOCK[$name][5] * 1000, $item->fresh()->on_hand);
            if ($quantity > 0) {
                $use[] = ['item' => $item->id, 'quantity' => (string) ($quantity / 1000), 'unit' => $item->unit];
            }
        }
        $save('use', $use);

        if (($day % 9 === 8 || $today) && $items['Potato']->fresh()->on_hand >= 250) {
            $reason = fake()->randomElement(['Spoiled', 'Expired', 'Spilled', 'Other']);
            $save('waste', [['item' => $items['Potato']->id, 'quantity' => '250', 'unit' => 'g']],
                ['reason_id' => $this->option('waste_reason', $reason), 'reason_text' => $reason === 'Other' ? 'Dropped while washing' : null]);
        }
        if ($day % 15 === 14) {
            $rows = [];
            foreach (['Rice' => -500, 'Onion' => 750] as $name => $change) {
                $hand = $items[$name]->fresh()->on_hand;
                $rows[] = ['item' => $items[$name]->id, 'quantity' => (string) (max(0, $hand + $change) / 1000), 'unit' => 'kg', 'expected' => $hand];
            }
            $this->stock->count(['date' => $date, 'token' => $this->token(), 'reason_text' => 'Fortnightly count'], $rows);
        }
    }

    private function spend(string $date, int $day, bool $today): void
    {
        if ($day % 4 !== 0 && ! $today) {
            return;
        }
        $types = ['Gas' => 1800, 'Rent' => 8000, 'Electricity' => 3200, 'Repairs' => 650, 'Other' => 240];
        $type = array_keys($types)[intdiv($day, 4) % 5];
        $expenses = app(ExpenseService::class);
        $expense = $expenses->add([
            'type_id' => $this->option('expense_type', $type), 'date' => $date, 'amount' => $types[$type] * 100 + fake()->numberBetween(0, 99),
            'method' => ['cash', 'upi', 'bank'][intdiv($day, 4) % 3], 'paid_to' => fake()->boolean() ? fake('en_IN')->name() : null,
            'note' => fake()->optional()->sentence(4), 'token' => $this->token(),
        ]);
        if ($day === 12) {
            $expenses->cancel($expense->id, 'Wrong amount');
        }
    }

    private function salaries(Collection $staff, Carbon $date, int $day): void
    {
        $service = app(SalaryService::class);
        $add = fn (string $job, string $type, string $month, int $amount, ?string $method = null) => $service->addEntry($staff[$job]->id, [
            'type' => $type, 'month' => $month, 'date' => $date->toDateString(), 'amount' => $amount, 'method' => $method, 'note' => ucfirst($type), 'token' => $this->token()]);
        if ($date->day === 1) {
            $month = $date->copy()->subMonth()->format('Y-m');
            $add('Cook', 'bonus', $month, 100000);
            $add('Helper', 'deduction', $month, 50000);
            foreach ($staff as $job => $row) {
                $left = $service->month($row->fresh(['periods', 'rates', 'entries']), $month)['left'];
                if ($left > 0) {
                    // The cleaner is always owed a part.
                    $add($job, 'payment', $month, $job === 'Cleaner' ? intdiv($left, 2) : $left, ['Cook' => 'bank', 'Helper' => 'upi'][$job] ?? 'cash');
                }
            }
        }
        if ($day === 15) {
            $service->cancelEntry($add('Cook', 'bonus', $date->format('Y-m'), 20000)->id, 'Wrong person');
            $service->changeRate($staff['Cook']->id, $date->format('Y-m'), 1900000);
            $service->leave($staff['Other']->id, $date->toDateString());
            $service->leave($staff['Cleaner']->id, $date->toDateString());
        }
        if ($day === 18) {
            $service->comeBack($staff['Cleaner']->id, $date->toDateString());
        }
    }

    private function cash(string $date, int $day): void
    {
        $service = app(CashService::class);
        $cash = $service->expected($date);
        $opening = $cash['opening'] ?? 2000000;
        $sum = $opening + $cash['change'];
        $putIn = $sum < 500000 ? 2000000 - $sum : 0;
        $out = $sum > 5000000 ? $sum - 2000000 : 0;
        $expected = $sum + $putIn - $out;
        $difference = [4 => 2000, 9 => -5000][$day % 10] ?? 0;
        $check = fn (int $off) => $service->save(['date' => $date, 'opening' => $opening, 'put_in' => $putIn, 'taken_out' => $out, 'counted' => $expected + $off,
            'expected' => $expected, 'note' => $off || $putIn || $out ? 'Checked with the manager' : null, 'token' => $this->token()]);
        if ($day === 11) {
            $service->cancelLatest($check(10000)->id, 'Counted wrong');
        }
        $check($difference);
    }

    // Made after the days are over, so a checked day also shows as changed.
    private function corrections(): void
    {
        $payment = PaymentHistory::active()->where('type', 'cash')->whereNotNull('before_amount')->latest('id')->firstOrFail();
        $this->billing->cancelPayment($payment->id, 'Wrong student');
        $this->billing->deleteAttendance(StudentAttendance::where('is_paid', '0')->latest('id')->firstOrFail()->id);
        $this->billing->cancelGuest(GuestMeal::where('type', 'card')->where('is_paid', '0')->firstOrFail()->id, 'Guest did not come');
        $this->billing->cancelGuest(GuestMeal::where('type', 'walk_in')->firstOrFail()->id, 'Guest did not come');
    }

    private function option(string $kind, ?string $name): ?int
    {
        return $name === null ? null : MenuOption::where('kind', $kind)->where('name', $name)->firstOrFail()->id;
    }

    private function clock(Carbon $date, int $hour): void
    {
        Carbon::setTestNow($date->copy()->setTime($hour, 0));
    }

    private function token(): string
    {
        return (string) Str::uuid();
    }
}

<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Expense;
use App\Models\GuestMeal;
use App\Models\PaymentHistory;
use App\Models\Staff;
use App\Models\StaffEntry;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StudentAttendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Two separate results, never mixed. Paise; money going out is below 0. Null is "Not available".
class MonthSummary
{
    public function __construct(private SalaryService $salaries) {}

    /**
     * @param  array<int, string>  $months  "2026-11"
     */
    public function for(array $months): array
    {
        return DB::transaction(function () use ($months) {
            $staff = Staff::with('periods', 'rates', 'entries')->get();

            return array_map(fn ($month) => $this->month($month, $staff), $months);
        });
    }

    private function month(string $month, $staff): array
    {
        $from = "$month-01";
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $in = fn ($query, string $date = 'date') => $query->whereBetween($date, [$from, $to]);
        $dates = "from=$from&to=$to";

        $payments = $in(PaymentHistory::active(), 'payment_date');
        // A payment before phase 4 has no reliable amount.
        $students = (clone $payments)->whereNull('before_amount')->exists() ? null : 100 * (int) $payments->sum('amount');
        $walkIns = 100 * (int) $in(GuestMeal::notCancelled()->where('type', 'walk_in'))->sum('amount');
        $purchases = (int) $in(StockEntry::notCancelled()->where('type', 'purchase'))->sum('total');
        $expenses = (int) $in(Expense::notCancelled())->sum('amount');
        $salariesPaid = (int) $in(StaffEntry::notCancelled()->where('type', 'payment'))->sum('amount');

        $own = $in(StudentAttendance::query())->selectRaw('count(*) as many, coalesce(sum(amount + extra_amount), 0) as total')->toBase()->first();
        $guests = $in(GuestMeal::notCancelled())->selectRaw('coalesce(sum(plates), 0) as many, coalesce(sum(amount), 0) as total')->toBase()->first();
        $costs = StockMovement::query()->whereHas('entry', fn ($q) => $in($q->notCancelled()->whereIn('type', ['use', 'waste', 'count'])))
            ->join('stock_entries', 'stock_entries.id', '=', 'stock_movements.stock_entry_id')->groupBy('stock_entries.type')
            ->selectRaw('stock_entries.type, sum(cost) as cost')->toBase()->pluck('cost', 'type');
        $cost = fn (string $type) => (int) ($costs[$type] ?? 0);
        $salariesDue = $staff->sum(fn ($person) => $this->salaries->month($person, $month)['due']);

        $link = fn (string $module, string $url) => AppSetting::isOn($module) ? $url : null;
        $left = [
            $this->line('students', 'Received from students', $students, "/changes?type=payment&$dates"),
            $this->line('walk_ins', 'Received from walk-ins', $walkIns, "/reports/meals?$dates"),
            $this->line('purchases', 'Purchases paid', -$purchases, "/purchases?show=saved&$dates"),
            $this->line('expenses', 'Expenses paid', -$expenses, $link('expenses', "/expenses?show=saved&$dates")),
            $this->line('salaries', 'Salaries paid', -$salariesPaid, $link('staff', '/staff')),
        ];
        $right = [
            $this->line('meals', 'Meals and extras marked', 100 * ((int) $own->total + (int) $guests->total), "/reports/meals?$dates"),
            $this->line('used', 'Food used, at cost', $cost('use'), "/purchases/report?$dates"),
            $this->line('wasted', 'Food wasted, at cost', $cost('waste'), "/purchases/report?$dates"),
            $this->line('counts', 'Stock count differences', $cost('count'), "/changes?type=stock&$dates"),
            $this->line('expenses', 'Expenses', -$expenses, $link('expenses', "/expenses?show=saved&$dates")),
            $this->line('salaries', 'Salaries due for the month', -$salariesDue, $link('staff', '/staff')),
        ];
        $served = (int) $own->many + (int) $guests->many;

        return compact('month', 'left', 'right', 'served') + [
            'left_over' => $students === null ? null : array_sum(array_column($left, 'amount')),
            'profit' => array_sum(array_column($right, 'amount')),
            'cost_per_meal' => $served ? StockItem::share(-$cost('use'), 1, $served) : null,
            'no_use' => ! isset($costs['use']),
        ];
    }

    private function line(string $key, string $label, ?int $amount, ?string $href): array
    {
        return compact('key', 'label', 'amount', 'href');
    }
}

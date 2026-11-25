<?php

namespace App\Services;

use App\Exceptions\RecordException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\CashCheck;
use App\Models\Expense;
use App\Models\GuestMeal;
use App\Models\PaymentHistory;
use App\Models\StaffEntry;
use App\Models\StockEntry;
use App\Support\Rupees;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CashService
{
    /**
     * What a check on this date covers. The opening is null for the first check: it is typed.
     *
     * @return array{opening: ?int, from: string, lines: array<string, int>, change: int}
     */
    public function expected(string $date): array
    {
        $last = CashCheck::notCancelled()->orderByDesc('date')->orderByDesc('id')->first();
        $from = $last ? Carbon::parse($last->date)->addDay()->toDateString() : $date;
        $lines = $this->sum($this->byDate($from, $date), $from, $date);

        return ['opening' => $last?->counted, 'from' => $from, 'lines' => $lines, 'change' => array_sum($lines)];
    }

    /**
     * @param  array{date: string, opening: ?int, put_in: int, taken_out: int, counted: int, expected: int, note: ?string, token: string}  $data
     */
    public function save(array $data): CashCheck
    {
        return $this->locked(function () use ($data) {
            if ($saved = CashCheck::savedWith($data['token'], 'cash')) {
                return $saved;
            }
            $last = CashCheck::notCancelled()->max('date');
            if ($last !== null && $data['date'] <= $last) {
                throw new RecordException('Cash was already checked for ' . $this->day($last) . '. Choose a later date.', 'date');
            }
            $cash = $this->expected($data['date']);
            $opening = $cash['opening'] ?? $data['opening'] ?? throw new RecordException('Enter the cash at the start of the day.', 'opening');
            $expected = $opening + $cash['change'] + $data['put_in'] - $data['taken_out'];
            if ($expected !== $data['expected']) {
                throw new RecordException('The amount has changed. Please check again.', 'expected');
            }
            if ($data['note'] === null && ($data['counted'] !== $expected || $data['put_in'] || $data['taken_out'])) {
                throw new RecordException('Write a note about the difference, or about cash put in or taken out.', 'note');
            }
            $check = CashCheck::create(compact('opening', 'expected') + $data + CashCheck::author());
            $difference = $check->counted - $expected;
            ActivityLog::record('cash.checked', null, 'Cash of ' . $this->day($check->date) . ': counted ' . Rupees::format($check->counted)
                . ($difference ? ', difference ' . Rupees::format($difference) : ''), ['cash_check_id' => $check->id]);

            return $check;
        });
    }

    public function cancelLatest(int $id, string $reason): CashCheck
    {
        return $this->locked(function () use ($id, $reason) {
            $check = CashCheck::lockedToCancel($id);
            if (CashCheck::notCancelled()->where('date', '>', $check->date)->exists()) {
                throw new RecordException('Only the latest check can be cancelled.', 'reason');
            }
            $check->cancelWith($reason);
            ActivityLog::record('cash.cancelled', null, 'Cash check of ' . $this->day($check->date) . " cancelled: $reason", ['cash_check_id' => $check->id]);

            return $check;
        });
    }

    /**
     * Checks whose records changed afterwards, with the amount expected now.
     *
     * @param  Collection<int, CashCheck>  $checks
     * @return array<int, int> check id => expected now
     */
    public function changed(Collection $checks): array
    {
        $checks = $checks->whereNull('cancelled_at');
        if ($checks->isEmpty()) {
            return [];
        }
        $dates = CashCheck::notCancelled()->orderBy('date')->pluck('date');
        $start = fn (CashCheck $check) => ($before = $dates->filter(fn ($date) => $date < $check->date)->last())
            ? Carbon::parse($before)->addDay()->toDateString() : $check->date;
        $days = $this->byDate($checks->map($start)->min(), $checks->max('date'));

        $now = [];
        foreach ($checks as $check) {
            $now[$check->id] = $check->opening + array_sum($this->sum($days, $start($check), $check->date)) + $check->put_in - $check->taken_out;
        }

        return array_filter($now, fn ($amount, $id) => $amount !== $checks->firstWhere('id', $id)->expected, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Cash of every source, in paise, money going out below 0.
     *
     * @return array<string, Collection<string, mixed>> line => date => amount
     */
    private function byDate(string $from, string $to): array
    {
        $sum = fn ($query, string $date, string $amount) => $query->whereBetween($date, [$from, $to])->groupBy($date)
            ->selectRaw("$date as day, sum($amount) as total")->toBase()->pluck('total', 'day');

        return [
            // Payments before phase 4 have no reliable amount.
            'students' => $sum(PaymentHistory::active()->where('type', 'cash')->whereNotNull('before_amount'), 'payment_date', 'amount * 100'),
            'walk_ins' => $sum(GuestMeal::notCancelled()->where('type', 'walk_in')->where('method', 'cash'), 'date', 'amount * 100'),
            'purchases' => $sum(StockEntry::notCancelled()->where('type', 'purchase')->where('method', 'cash'), 'date', '-total'),
            'expenses' => $sum(Expense::notCancelled()->where('method', 'cash'), 'date', '-amount'),
            'salaries' => $sum(StaffEntry::notCancelled()->where('type', 'payment')->where('method', 'cash'), 'date', '-amount'),
        ];
    }

    private function sum(array $days, string $from, string $to): array
    {
        return array_map(fn ($rows) => (int) $rows->filter(fn ($total, $day) => $day >= $from && $day <= $to)->sum(fn ($total) => (int) $total), $days);
    }

    private function locked(Closure $work): mixed
    {
        return DB::transaction(function () use ($work) {
            // First query on purpose: two checks wait for each other here.
            AppSetting::whereKey('module_cash')->lockForUpdate()->first();

            return $work();
        }, 3);
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('j F Y');
    }
}

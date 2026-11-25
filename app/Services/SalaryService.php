<?php

namespace App\Services;

use App\Exceptions\RecordException;
use App\Models\ActivityLog;
use App\Models\MenuOption;
use App\Models\Staff;
use App\Models\StaffEntry;
use App\Models\StaffSalaryRate;
use App\Support\Rupees;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Every write to staff. A month is "2026-11"; it is saved as its first day.
class SalaryService
{
    /**
     * Load periods, rates and entries first when there are many staff.
     *
     * @return array{rate: int, bonus: int, deduction: int, due: int, paid: int, left: int}
     */
    public function month(Staff $staff, string $month): array
    {
        $first = "$month-01";
        $entries = $staff->entries->where('cancelled_at', null)->where('month', $first);
        $sum = fn (string $type) => (int) $entries->where('type', $type)->sum('amount');
        $rate = 0;
        if ($this->isEmployed($staff, $month)) {
            $rate = ($staff->rates->where('from_month', '<=', $first)->last() ?? $staff->rates->first())->amount;
        }
        $due = $rate === 0 ? 0 : $rate + $sum('bonus') - $sum('deduction');

        return ['rate' => $rate, 'bonus' => $sum('bonus'), 'deduction' => $sum('deduction'), 'due' => $due, 'paid' => $sum('payment'), 'left' => $due - $sum('payment')];
    }

    public function isEmployed(Staff $staff, string $month): bool
    {
        return $staff->periods->contains(fn ($period) => substr($period->joined_on, 0, 7) <= $month
            && ($period->left_on === null || substr($period->left_on, 0, 7) >= $month));
    }

    /**
     * Months from joining to now, later ones only with entries; newest first.
     *
     * @return array<int, string>
     */
    public function months(Staff $staff): array
    {
        $months = $staff->entries->pluck('month')->map(fn ($month) => substr($month, 0, 7));
        foreach ($staff->periods as $period) {
            $end = min(substr($period->left_on ?? '9999', 0, 7), today()->format('Y-m'));
            for ($month = Carbon::parse($period->joined_on)->startOfMonth(); $month->format('Y-m') <= $end; $month->addMonth()) {
                $months->push($month->format('Y-m'));
            }
        }

        return $months->unique()->sortDesc()->values()->all();
    }

    /**
     * @param  array{name: string, phone: ?string, job_id: ?int, note: ?string}  $data
     */
    public function addStaff(array $data, string $joinedOn, int $salary): Staff
    {
        return DB::transaction(function () use ($data, $joinedOn, $salary) {
            $this->checkJob($data['job_id'], null);
            $staff = Staff::create($data);
            $staff->periods()->create(['joined_on' => $joinedOn]);
            $staff->rates()->create(['from_month' => substr($joinedOn, 0, 7) . '-01', 'amount' => $salary]);
            $this->record('added', $staff, 'added to staff from ' . $this->day($joinedOn) . ', ' . Rupees::format($salary) . ' a month');

            return $staff;
        }, 3);
    }

    public function changeStaff(int $id, array $data, string $joinedOn): Staff
    {
        return $this->forStaff($id, function (Staff $staff) use ($data, $joinedOn) {
            $this->checkJob($data['job_id'], $staff->job_id);
            $period = $staff->periods->last();
            $before = $staff->periods->slice(-2, 1)->first();
            if ($staff->periods->count() > 1 && $joinedOn <= $before->left_on) {
                throw new RecordException('This is before ' . $this->day($before->left_on) . ', when the last time here ended.', 'joined_on');
            }
            if ($period->left_on !== null && $joinedOn > $period->left_on) {
                throw new RecordException('This is after the leaving date.', 'joined_on');
            }
            $staff->fill($data);
            $period->fill(['joined_on' => $joinedOn]);
            $changes = array_intersect_key(['name' => 'name', 'phone' => 'phone', 'job_id' => 'job', 'note' => 'note'], $staff->getDirty());
            if ($period->isDirty()) {
                $changes[] = 'joining date';
            }
            if ($changes) {
                $name = $staff->getOriginal('name');
                $staff->save();
                $period->save();
                $this->check($staff, 'joined_on');
                ActivityLog::record('staff.changed', null, "$name changed: " . implode(', ', $changes), ['staff_id' => $staff->id]);
            }

            return $staff;
        });
    }

    /**
     * @param  array{type: string, month: string, date: string, amount: int, method: ?string, note: ?string, token: string}  $data
     */
    public function addEntry(int $staffId, array $data): StaffEntry
    {
        $same = ['staff_id' => $staffId, 'type' => $data['type']];
        try {
            return $this->forStaff($staffId, function (Staff $staff) use ($data, $same) {
                if ($saved = StaffEntry::savedWith($data['token'], 'entry', $same)) {
                    return $saved;
                }
                $this->checkMonth($staff, $data['month']);
                $entry = $staff->entries()->create(['month' => $data['month'] . '-01'] + $data + StaffEntry::author());
                $this->check($staff, 'entry');
                $this->record($data['type'], $staff, StaffEntry::TYPES[$data['type']] . ' ' . Rupees::format($data['amount']) . ' for ' . $this->name($data['month']));

                return $entry;
            });
        } catch (UniqueConstraintViolationException $e) {
            return StaffEntry::savedWith($data['token'], 'entry', $same) ?? throw $e;
        }
    }

    public function cancelEntry(int $entryId, string $reason): StaffEntry
    {
        $staffId = StaffEntry::findOrFail($entryId)->staff_id;

        return $this->forStaff($staffId, function (Staff $staff) use ($entryId, $reason) {
            $entry = StaffEntry::lockedToCancel($entryId);
            $entry->cancelWith($reason);
            $this->check($staff, 'reason');
            $this->record('cancelled', $staff, StaffEntry::TYPES[$entry->type] . ' ' . Rupees::format($entry->amount) . ' for ' . $this->name(substr($entry->month, 0, 7)) . " cancelled: $reason");

            return $entry;
        });
    }

    public function changeRate(int $staffId, string $fromMonth, int $amount): void
    {
        $this->forStaff($staffId, function (Staff $staff) use ($fromMonth, $amount) {
            $this->checkMonth($staff, $fromMonth);
            $rate = $staff->rates()->firstOrNew(['from_month' => "$fromMonth-01"]);
            $before = $rate->amount;
            if ($before === $amount) {
                return;
            }
            $rate->fill(['amount' => $amount])->save();
            $this->check($staff, 'amount');
            ActivityLog::record('staff.rate', null, "Salary of {$staff->name} from " . $this->name($fromMonth) . ': ' . Rupees::format($amount)
                . ($before === null ? '' : ' (before ' . Rupees::format($before) . ')'), ['staff_id' => $staff->id]);
        });
    }

    public function leave(int $staffId, string $date): void
    {
        $this->forStaff($staffId, function (Staff $staff) use ($date) {
            $period = $staff->periods->last();
            if ($period->left_on !== null) {
                throw new RecordException('Already marked as left.', 'date');
            }
            if ($date < $period->joined_on) {
                throw new RecordException('This is before the joining date.', 'date');
            }
            $later = $staff->entries->where('cancelled_at', null)->pluck('month')->map(fn ($month) => substr($month, 0, 7))
                ->filter(fn ($month) => $month > substr($date, 0, 7))->unique()->sort()->map(fn ($month) => $this->name($month));
            if ($later->isNotEmpty()) {
                throw new RecordException('There are entries for ' . $later->implode(', ') . '. Cancel them first.', 'date');
            }
            $period->update(['left_on' => $date]);
            $this->check($staff, 'date');
            $this->record('left', $staff, 'left on ' . $this->day($date));
        });
    }

    public function comeBack(int $staffId, string $date): void
    {
        $this->forStaff($staffId, function (Staff $staff) use ($date) {
            $left = $staff->periods->last()->left_on;
            if ($left === null) {
                throw new RecordException('Already working.', 'date');
            }
            if ($date <= $left) {
                throw new RecordException('Choose a date after ' . $this->day($left) . '.', 'date');
            }
            $staff->periods()->create(['joined_on' => $date]);
            $this->record('returned', $staff, 'came back on ' . $this->day($date));
        });
    }

    private function forStaff(int $id, Closure $work): mixed
    {
        return DB::transaction(function () use ($id, $work) {
            // First query on purpose: an earlier read would fix an old MariaDB snapshot.
            $staff = Staff::whereKey($id)->lockForUpdate()->firstOrFail();

            return $work($staff->load('periods', 'rates', 'entries'));
        }, 3);
    }

    private function checkMonth(Staff $staff, string $month): void
    {
        if (! $this->isEmployed($staff, $month)) {
            throw new RecordException("{$staff->name} does not work here in " . $this->name($month) . '.', 'month');
        }
        if ($month > today()->startOfMonth()->addMonths(12)->format('Y-m')) {
            throw new RecordException('Choose a month in the next 12 months.', 'month');
        }
    }

    // After every write: no month has more paid than due, and none has entries outside employment.
    private function check(Staff $staff, string $field): void
    {
        $staff->load('periods', 'rates', 'entries');
        foreach ($staff->entries->where('cancelled_at', null)->pluck('month')->unique()->sort() as $first) {
            $month = substr($first, 0, 7);
            if (! $this->isEmployed($staff, $month)) {
                throw new RecordException('There are entries for ' . $this->name($month) . '. Cancel them first.', $field);
            }
            $salary = $this->month($staff, $month);
            if ($salary['due'] < 0) {
                throw new RecordException('The deduction is more than the salary of ' . $this->name($month) . '.', $field);
            }
            if ($salary['left'] < 0) {
                throw new RecordException($this->name($month) . ' would have more paid (' . Rupees::format($salary['paid']) . ') than due (' . Rupees::format($salary['due']) . ').', $field);
            }
        }
    }

    private function checkJob(?int $id, ?int $current): void
    {
        $job = $id === null ? null : MenuOption::where('kind', 'staff_job')->whereKey($id)->sharedLock()->first();
        if ($id !== null && (! $job || (! $job->active && $id !== $current))) {
            throw new RecordException('Choose a job from the list.', 'job_id');
        }
    }

    private function record(string $what, Staff $staff, string $text): void
    {
        ActivityLog::record("staff.$what", null, "{$staff->name}: $text", ['staff_id' => $staff->id]);
    }

    private function name(string $month): string
    {
        return Carbon::parse("$month-01")->format('F Y');
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('j F Y');
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Staff;
use App\Services\SalaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckMoney extends Command
{
    protected $signature = 'canteen:check-money';

    protected $description = 'Check balances, entries and payments. Reads only; prints IDs and amounts, no names.';

    public function handle(): int
    {
        // shortcut: loads every row, use chunks if entries reach hundreds of thousands.
        $bills = DB::table('payments')->orderBy('id')->get();
        $entries = DB::table('student_attendances')->orderBy('id')->get();
        $payments = DB::table('payment_histories')->get()->keyBy('id');
        $linked = Schema::hasColumn('student_attendances', 'payment_history_id');
        $batches = Schema::hasTable('attendance_batches') ? DB::table('attendance_batches')->get()->keyBy('id') : null;
        $extras = Schema::hasTable('attendance_extras') ? DB::table('attendance_extras')->get()->groupBy('attendance_id') : collect();
        $guests = Schema::hasTable('guest_meals') ? DB::table('guest_meals')->orderBy('id')->get() : collect();
        $guestExtras = Schema::hasTable('guest_meal_extras') ? DB::table('guest_meal_extras')->get()->groupBy('guest_meal_id') : collect();
        $problems = [];

        $total = fn ($rows) => (int) $rows->sum(fn ($row) => (int) $row->amount + (int) $row->extra_amount);
        $unpaid = $entries->where('is_paid', '0')->groupBy('student_id')->map($total);
        $unpaidGuests = $guests->where('type', 'card')->where('is_paid', '0')->whereNull('cancelled_at')->groupBy('student_id')
            ->map(fn ($rows) => (int) $rows->sum('amount'));

        foreach ($bills as $bill) {
            $expected = ($unpaid[$bill->student_id] ?? 0) + ($unpaidGuests[$bill->student_id] ?? 0);
            if ((int) $bill->amount !== $expected) {
                $problems[] = "bill {$bill->id}: amount {$bill->amount}, unpaid entries $expected";
            }
            if ($bill->pending_amount > 0 && $bill->advance_amount > 0) {
                $problems[] = "bill {$bill->id}: pending and advance are both above zero";
            }
            if (min($bill->amount, $bill->pending_amount, $bill->advance_amount) < 0) {
                $problems[] = "bill {$bill->id}: negative part";
            }
        }

        $this->duplicates($entries->whereNotNull('student_id')->whereNotNull('meal_id'),
            fn ($row) => "{$row->student_id}|{$row->meal_id}|{$row->date}", 'duplicate entries', $problems);
        $this->duplicates($bills->whereNotNull('student_id'), fn ($row) => $row->student_id, 'duplicate bills', $problems);

        $billed = $bills->pluck('student_id')->flip();
        foreach ($entries as $entry) {
            if (! $billed->has($entry->student_id)) {
                $problems[] = "entry {$entry->id}: no bill";
            }
            if (! preg_match('/^\d*$/', (string) $entry->amount) || ! preg_match('/^\d*$/', (string) $entry->extra_amount)) {
                $problems[] = "entry {$entry->id}: decimal amount";
            }
            if ($extras->has($entry->id)) {
                $sum = $extras[$entry->id]->sum(fn ($row) => $row->quantity * $row->price);
                if ((int) $entry->extra_amount !== (int) $sum) {
                    $problems[] = "entry {$entry->id}: extra amount {$entry->extra_amount}, extra items $sum";
                }
                if ($entry->extra_meal_id !== null) {
                    $problems[] = "entry {$entry->id}: old and new extra items";
                }
            }
            if ($batches !== null && $entry->batch_id !== null) {
                $batch = $batches[$entry->batch_id];
                if ($batch->undone_at !== null) {
                    $problems[] = "entry {$entry->id}: batch {$batch->id} was undone";
                }
                if ($batch->meal_id !== $entry->meal_id || $batch->date !== $entry->date) {
                    $problems[] = "entry {$entry->id}: meal or date differs from batch {$batch->id}";
                }
            }
            if (! $linked || $entry->payment_history_id === null) {
                continue;
            }
            $payment = $payments[$entry->payment_history_id];
            if ($entry->is_paid === '0') {
                $problems[] = "entry {$entry->id}: unpaid but linked to payment {$payment->id}";
            }
            if ($payment->cancelled_at !== null) {
                $problems[] = "entry {$entry->id}: linked to cancelled payment {$payment->id}";
            }
            if ($payment->student_id !== $entry->student_id) {
                $problems[] = "entry {$entry->id}: payment {$payment->id} belongs to another student";
            }
        }

        foreach ($guests as $guest) {
            $sum = $guest->plates * $guest->price + $guest->extra_amount;
            if ((int) $guest->amount !== (int) $sum) {
                $problems[] = "guest {$guest->id}: amount {$guest->amount}, expected $sum";
            }
            $items = ($guestExtras[$guest->id] ?? collect())->sum(fn ($row) => $row->quantity * $row->price);
            if ((int) $guest->extra_amount !== (int) $items) {
                $problems[] = "guest {$guest->id}: extra amount {$guest->extra_amount}, extra items $items";
            }
            $card = $guest->type === 'card';
            if ($card ? $guest->student_id === null || $guest->method !== null
                : $guest->student_id !== null || $guest->payment_history_id !== null || $guest->method === null || $guest->is_paid !== '1') {
                $problems[] = "guest {$guest->id}: fields do not fit its type";

                continue;
            }
            if (! $card) {
                continue;
            }
            if (! $billed->has($guest->student_id)) {
                $problems[] = "guest {$guest->id}: no bill";
            }
            if ($guest->cancelled_at !== null && ($guest->is_paid === '1' || $guest->payment_history_id !== null)) {
                $problems[] = "guest {$guest->id}: cancelled but paid or linked";
            }
            if ($guest->payment_history_id === null) {
                if ($guest->is_paid === '1') {
                    $problems[] = "guest {$guest->id}: paid but not linked";
                }

                continue;
            }
            $payment = $payments[$guest->payment_history_id];
            if ($guest->is_paid === '0') {
                $problems[] = "guest {$guest->id}: unpaid but linked to payment {$payment->id}";
            }
            if ($payment->cancelled_at !== null) {
                $problems[] = "guest {$guest->id}: linked to cancelled payment {$payment->id}";
            }
            if ($payment->student_id !== $guest->student_id) {
                $problems[] = "guest {$guest->id}: payment {$payment->id} belongs to another student";
            }
        }

        $this->phase8($problems);

        foreach ($problems as $problem) {
            $this->line($problem);
        }
        $this->info(count($problems) . ' problems in ' . $bills->count() . ' bills and ' . $entries->count() . ' entries.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    private function phase8(array &$problems): void
    {
        foreach (['expenses', 'staff_entries', 'cash_checks'] as $table) {
            $rows = Schema::hasTable($table) ? DB::table($table)->orderBy('id')->get() : collect();
            foreach ($rows as $row) {
                if (($row->cancelled_at === null) !== ($row->cancel_reason === null)) {
                    $problems[] = "$table {$row->id}: cancelled without reason or time";
                }
            }
            if ($table === 'cash_checks') {
                $before = null;
                foreach ($rows->whereNull('cancelled_at')->sortBy([['date', 'asc'], ['id', 'asc']]) as $row) {
                    if ($before !== null && (int) $row->opening !== (int) $before->counted) {
                        $problems[] = "cash check {$row->id}: opening {$row->opening}, previous counted {$before->counted}";
                    }
                    $before = $row;
                }
            }
        }
        if (! Schema::hasTable('staff_entries')) {
            return;
        }
        $salaries = app(SalaryService::class);
        foreach (Staff::with('periods', 'rates', 'entries')->get() as $staff) {
            $entries = $staff->entries->whereNull('cancelled_at');
            foreach ($entries as $entry) {
                if (! $salaries->isEmployed($staff, substr($entry->month, 0, 7))) {
                    $problems[] = "staff entry {$entry->id}: month outside employment";
                }
            }
            foreach ($entries->pluck('month')->unique() as $first) {
                $month = substr($first, 0, 7);
                $salary = $salaries->month($staff, $month);
                if ($salary['paid'] > $salary['due']) {
                    $problems[] = "staff {$staff->id}: $month paid {$salary['paid']}, due {$salary['due']}";
                }
            }
        }
    }

    private function duplicates($rows, callable $key, string $label, array &$problems): void
    {
        foreach ($rows->groupBy($key)->filter(fn ($group) => $group->count() > 1) as $group) {
            $problems[] = "$label: " . $group->pluck('id')->implode(', ');
        }
    }
}

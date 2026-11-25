<?php

namespace App\Services;

use App\Exceptions\RecordException;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\MenuOption;
use App\Support\Rupees;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    /**
     * @param  array{type_id: int, date: string, amount: int, method: string, paid_to: ?string, note: ?string, token: string}  $data
     */
    public function add(array $data): Expense
    {
        try {
            return Expense::savedWith($data['token'], 'expense') ?? DB::transaction(function () use ($data) {
                // Locked so it cannot be deleted or turned off meanwhile.
                $type = MenuOption::where('kind', 'expense_type')->whereKey($data['type_id'])->sharedLock()->first();
                if (! $type?->active) {
                    throw new RecordException('Choose a type from the list.', 'type_id');
                }
                $expense = Expense::create($data + ['type_name' => $type->name] + Expense::author());
                $this->record('added', $expense);

                return $expense;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            return Expense::savedWith($data['token'], 'expense') ?? throw $e;
        }
    }

    public function cancel(int $id, string $reason): Expense
    {
        return DB::transaction(function () use ($id, $reason) {
            $expense = Expense::lockedToCancel($id);
            $expense->cancelWith($reason);
            $this->record('cancelled', $expense, ": $reason");

            return $expense;
        }, 3);
    }

    private function record(string $what, Expense $expense, string $more = ''): void
    {
        $text = "{$expense->type_name} " . Rupees::format($expense->amount) . ' on ' . Carbon::parse($expense->date)->format('j F Y');
        ActivityLog::record("expense.$what", null, $text . ($what === 'cancelled' ? ' cancelled' : '') . $more, ['expense_id' => $expense->id]);
    }
}

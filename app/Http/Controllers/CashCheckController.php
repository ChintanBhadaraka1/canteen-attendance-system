<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Concerns\ReadsStockRows;
use App\Models\CashCheck;
use App\Services\CashService;
use App\Support\Rupees;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CashCheckController extends Controller
{
    use ListsRecords, ReadsStockRows;

    public function index(Request $request, CashService $cash)
    {
        $last = CashCheck::notCancelled()->max('date');
        $date = $this->optionalDates($request->merge(['from' => $request->input('date')]))['from'] ?: today()->toDateString();
        // Null when this date can no longer be checked.
        $date = $date > today()->toDateString() || $date < today()->subDays(366)->toDateString() || ($last !== null && $date <= $last) ? null : $date;

        $checks = $this->pageOf(CashCheck::orderByDesc('date')->orderByDesc('id'));
        $changed = $cash->changed($checks->getCollection());

        return Inertia::render('Cash/Index', [
            'date' => $date,
            'last' => $last,
            'cash' => $date === null ? null : $cash->expected($date),
            'checks' => $checks->through(fn ($row) => $row->only('id', 'date', 'opening', 'expected', 'put_in', 'taken_out', 'counted', 'note', 'user_name', 'cancel_reason') + [
                'difference' => $row->counted - $row->expected, 'cancelled' => $row->cancelled_at !== null,
                'changed' => isset($changed[$row->id]), 'now' => $changed[$row->id] ?? null, 'cancellable' => $row->cancelled_at === null && $row->date === $last,
            ]),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, CashService $cash)
    {
        $money = ['bail', 'nullable', ...$this->moneyRule()];
        $input = $request->validate($this->entryRules() + [
            'opening' => $money, 'put_in' => $money, 'taken_out' => $money,
            'counted' => ['bail', 'required', ...$this->moneyRule()],
            'expected' => ['bail', 'required', 'integer'],
        ], ['counted.required' => 'Enter the cash you counted.'] + $this->messages());

        $amount = fn (string $key) => isset($input[$key]) ? Rupees::parse($input[$key]) : null;
        $check = $this->orRefuse(fn () => $cash->save([
            'date' => $input['date'], 'opening' => $amount('opening'), 'put_in' => $amount('put_in') ?? 0, 'taken_out' => $amount('taken_out') ?? 0,
            'counted' => $amount('counted'), 'expected' => (int) $input['expected'], 'note' => $input['note'] ?? null, 'token' => $input['token'],
        ]));

        return redirect()->route('cash.index')->with('success', 'Cash check saved: ' . Rupees::format($check->counted) . '.');
    }

    public function cancel(Request $request, CashService $cash, string $check)
    {
        $id = CashCheck::findOrFail($check)->id;
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $this->orRefuse(fn () => $cash->cancelLatest($id, $input['reason']));

        return redirect()->route('cash.index')->with('success', 'Cash check cancelled.');
    }
}

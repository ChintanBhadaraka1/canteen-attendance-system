<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsStockRows;
use App\Models\Staff;
use App\Models\StaffEntry;
use App\Models\StockEntry;
use App\Services\SalaryService;
use App\Support\Rupees;
use Illuminate\Http\Request;

class SalaryController extends Controller
{
    use ReadsStockRows;

    public function rate(Request $request, SalaryService $salaries, string $staff)
    {
        $id = Staff::findOrFail($staff)->id;
        $input = $request->validate(['month' => ['bail', 'required', 'string', 'date_format:Y-m'], 'amount' => ['bail', 'required', ...$this->amountRule()]], $this->texts());
        $this->orRefuse(fn () => $salaries->changeRate($id, $input['month'], Rupees::parse($input['amount'])));

        return redirect()->route('staff.show', $id)->with('success', 'Salary saved.');
    }

    public function store(Request $request, SalaryService $salaries, string $staff)
    {
        $id = Staff::findOrFail($staff)->id;
        $input = $request->validate([
            'type' => ['bail', 'required', 'string', 'in:' . implode(',', array_keys(StaffEntry::TYPES))],
            'month' => ['bail', 'required', 'string', 'date_format:Y-m'],
            'amount' => ['bail', 'required', ...$this->amountRule()],
            'method' => ['bail', 'nullable', 'required_if:type,payment', 'string', 'in:' . implode(',', array_keys(StockEntry::METHODS))],
        ] + array_replace($this->entryRules(), ['note' => ['bail', 'nullable', 'required_unless:type,payment', 'string', 'max:200']]), $this->texts());

        $entry = $this->orRefuse(fn () => $salaries->addEntry($id, [
            'type' => $input['type'], 'month' => $input['month'], 'date' => $input['date'], 'amount' => Rupees::parse($input['amount']),
            'method' => $input['type'] === 'payment' ? $input['method'] : null, 'note' => $input['note'] ?? null, 'token' => $input['token'],
        ]));

        return redirect()->route('staff.show', $id)->with('success', StaffEntry::TYPES[$entry->type] . ' ' . Rupees::format($entry->amount) . ' saved.');
    }

    public function cancel(Request $request, SalaryService $salaries, string $entry)
    {
        $row = StaffEntry::findOrFail($entry);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $this->orRefuse(fn () => $salaries->cancelEntry($row->id, $input['reason']));

        return redirect()->route('staff.show', $row->staff_id)->with('success', StaffEntry::TYPES[$row->type] . ' ' . Rupees::format($row->amount) . ' cancelled.');
    }

    private function texts(): array
    {
        return ['month.*' => 'Choose a month.', 'amount.required' => 'Enter the amount.', 'method.*' => 'Choose cash, UPI or bank.', 'note.required_unless' => 'Write what it is for.'] + $this->messages();
    }
}

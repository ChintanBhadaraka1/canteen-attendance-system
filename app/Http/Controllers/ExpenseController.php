<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Concerns\ReadsStockRows;
use App\Models\Expense;
use App\Models\MenuOption;
use App\Models\StockEntry;
use App\Services\ExpenseService;
use App\Support\Csv;
use App\Support\Rupees;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    use ListsRecords, ReadsStockRows;

    public function index(Request $request)
    {
        [$query, $filters] = $this->filtered($request);
        $totals = (clone $query)->notCancelled()->reorder()->groupBy('type_name')->orderBy('type_name')
            ->selectRaw('type_name as name, sum(amount) as total')->toBase()->get()->map(fn ($row) => ['name' => $row->name, 'total' => (int) $row->total]);

        return Inertia::render('Expenses/Index', [
            'expenses' => $this->pageOf($query)->through(fn ($row) => $row->only('id', 'date', 'type_name', 'amount', 'paid_to', 'note', 'user_name', 'cancel_reason') + [
                'method' => StockEntry::METHODS[$row->method], 'cancelled' => $row->cancelled_at !== null,
            ]),
            'filters' => $filters,
            'types' => MenuOption::of('expense_type')->get()->map->only('id', 'name'),
            'totals' => $totals,
            'total' => $totals->sum('total'),
        ]);
    }

    public function download(Request $request)
    {
        [$query] = $this->filtered($request);

        return Csv::download('expenses.csv', ['Date', 'Type', 'Amount', 'Paid by', 'Paid to', 'Note', 'Cancelled because'],
            $query->lazy()->map(fn ($row) => [$row->date, Csv::cell($row->type_name), Rupees::show($row->amount), StockEntry::METHODS[$row->method],
                Csv::cell($row->paid_to), Csv::cell($row->note), Csv::cell($row->cancel_reason)]));
    }

    public function create()
    {
        return Inertia::render('Expenses/Form', [
            'types' => MenuOption::of('expense_type')->where('active', true)->get()->map->only('id', 'name'),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, ExpenseService $expenses)
    {
        $input = $request->validate($this->entryRules() + [
            'type_id' => ['bail', 'required', 'integer'],
            'amount' => ['bail', 'required', ...$this->amountRule()],
            'method' => ['bail', 'required', 'string', 'in:' . implode(',', array_keys(StockEntry::METHODS))],
            'paid_to' => ['nullable', 'string', 'max:60'],
        ], ['type_id.*' => 'Choose a type.', 'method.*' => 'Choose cash, UPI or bank.', 'amount.required' => 'Enter the amount.'] + $this->messages());

        $expense = $this->orRefuse(fn () => $expenses->add([
            'type_id' => (int) $input['type_id'], 'date' => $input['date'], 'amount' => Rupees::parse($input['amount']), 'method' => $input['method'],
            'paid_to' => $input['paid_to'] ?? null, 'note' => $input['note'] ?? null, 'token' => $input['token'],
        ]));

        return redirect()->route('expenses.index')->with('success', "{$expense->type_name} " . Rupees::format($expense->amount) . ' saved.');
    }

    public function cancel(Request $request, ExpenseService $expenses, string $expense)
    {
        $id = Expense::findOrFail($expense)->id;
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $row = $this->orRefuse(fn () => $expenses->cancel($id, $input['reason']));

        return redirect()->back(fallback: route('expenses.index'))->with('success', "{$row->type_name} " . Rupees::format($row->amount) . ' cancelled.');
    }

    private function filtered(Request $request): array
    {
        $filters = $this->listParams($request, ['date'], 'date', 'desc') + $this->optionalDates($request);
        $filters['type'] = MenuOption::of('expense_type')->find((int) $this->text($request, 'type'))?->id ?? '';
        $method = $this->text($request, 'method');
        $filters['method'] = isset(StockEntry::METHODS[$method]) ? $method : '';
        $show = $this->text($request, 'show');
        $filters['show'] = in_array($show, ['saved', 'cancelled'], true) ? $show : '';

        $query = Expense::orderBy('date', $filters['dir'])->orderBy('id', $filters['dir']);
        $this->searchIn($query, ['type_name', 'paid_to', 'note'], $filters['search']);
        $query->when($filters['from'], fn ($q, $date) => $q->where('date', '>=', $date))
            ->when($filters['to'], fn ($q, $date) => $q->where('date', '<=', $date))
            ->when($filters['type'], fn ($q, $id) => $q->where('type_id', $id))
            ->when($filters['method'], fn ($q, $method) => $q->where('method', $method))
            ->when($filters['show'] === 'saved', fn ($q) => $q->notCancelled())
            ->when($filters['show'] === 'cancelled', fn ($q) => $q->whereNotNull('cancelled_at'));

        return [$query, $filters];
    }
}

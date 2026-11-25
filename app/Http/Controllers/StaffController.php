<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsStockRows;
use App\Models\MenuOption;
use App\Models\Staff;
use App\Models\StaffEntry;
use App\Models\StockEntry;
use App\Services\SalaryService;
use App\Support\Rupees;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class StaffController extends Controller
{
    use ReadsStockRows;

    public function index(SalaryService $salaries)
    {
        $month = today()->format('Y-m');

        return Inertia::render('Staff/Index', [
            'staff' => Staff::with('job', 'periods', 'rates', 'entries')->orderBy('name')->get()->map(fn ($staff) => $staff->only('id', 'name') + [
                'job' => $staff->job?->name, 'working' => $staff->isWorking(), 'salary' => $salaries->month($staff, $month)['rate'], 'left' => $salaries->month($staff, $month)['left'],
            ])->sortByDesc('working')->values(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Staff/Form', ['staff' => null, 'jobs' => $this->jobs()]);
    }

    public function store(Request $request, SalaryService $salaries)
    {
        $input = $request->validate($this->rules() + ['salary' => ['bail', 'required', ...$this->amountRule()]], $this->texts());
        $staff = $this->orRefuse(fn () => $salaries->addStaff($this->data($input), $input['joined_on'], Rupees::parse($input['salary'])));

        return redirect()->route('staff.show', $staff->id)->with('success', "{$staff->name} added.");
    }

    public function show(SalaryService $salaries, string $staff)
    {
        $row = Staff::with('job', 'periods', 'rates', 'entries')->findOrFail($staff);
        $months = collect($salaries->months($row))->map(fn ($month) => compact('month') + $salaries->month($row, $month))
            ->filter(fn ($month, $index) => $index < 24 || $month['left'] !== 0)->values();

        return Inertia::render('Staff/Show', [
            'staff' => $row->only('id', 'name', 'phone', 'note') + ['job' => $row->job?->name, 'working' => $row->isWorking()],
            'periods' => $row->periods->map->only('id', 'joined_on', 'left_on')->reverse()->values(),
            'rates' => $row->rates->map->only('from_month', 'amount')->reverse()->values(),
            'months' => $months,
            'entries' => $row->entries->reverse()->take(50)->map(fn ($entry) => $entry->only('id', 'type', 'date', 'amount', 'note', 'user_name', 'cancel_reason') + [
                'label' => StaffEntry::TYPES[$entry->type], 'month' => substr($entry->month, 0, 7), 'method' => StockEntry::METHODS[$entry->method] ?? null,
                'cancelled' => $entry->cancelled_at !== null,
            ])->values(),
            'choices' => $this->choices($row, $salaries),
            'token' => (string) Str::uuid(),
        ]);
    }

    // Months an entry can be for.
    private function choices(Staff $staff, SalaryService $salaries): array
    {
        $months = [];
        $first = Carbon::parse($staff->periods->first()->joined_on)->startOfMonth();
        for ($month = today()->startOfMonth()->addMonths(12); $month >= $first; $month->subMonth()) {
            if ($salaries->isEmployed($staff, $month->format('Y-m'))) {
                $months[] = $month->format('Y-m');
            }
        }

        return $months;
    }

    public function edit(string $staff)
    {
        $row = Staff::with('periods')->findOrFail($staff);

        return Inertia::render('Staff/Form', [
            'staff' => $row->only('id', 'name', 'phone', 'job_id', 'note') + ['joined_on' => $row->periods->last()->joined_on],
            'jobs' => $this->jobs($row->job_id),
        ]);
    }

    public function update(Request $request, SalaryService $salaries, string $staff)
    {
        $id = Staff::findOrFail($staff)->id;
        $input = $request->validate($this->rules(), $this->texts());
        $this->orRefuse(fn () => $salaries->changeStaff($id, $this->data($input), $input['joined_on']));

        return redirect()->route('staff.show', $id)->with('success', 'Change saved.');
    }

    public function status(Request $request, SalaryService $salaries, string $staff)
    {
        $row = Staff::findOrFail($staff);
        $input = $request->validate([
            'status' => ['bail', 'required', 'string', 'in:left,working'],
            'date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], $this->texts());
        $this->orRefuse(fn () => $input['status'] === 'left' ? $salaries->leave($row->id, $input['date']) : $salaries->comeBack($row->id, $input['date']));

        return redirect()->route('staff.show', $row->id)->with('success', "{$row->name} " . ($input['status'] === 'left' ? 'marked as left.' : 'is working again.'));
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'phone' => ['bail', 'nullable', 'string', 'regex:/^\d{10}$/'],
            'job_id' => ['bail', 'nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
            'joined_on' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:' . today()->subYears(50)->toDateString()],
        ];
    }

    private function texts(): array
    {
        return [
            'name.*' => 'Enter a name of 2 to 60 letters.', 'phone.*' => 'Enter 10 digits.', 'job_id.*' => 'Choose a job from the list.', 'salary.required' => 'Enter the salary for a month.',
            'joined_on.*' => 'Choose the joining date, today or earlier.', 'date.*' => 'Choose a date, today or earlier.',
        ];
    }

    private function data(array $input): array
    {
        return ['name' => $input['name'], 'phone' => $input['phone'] ?? null, 'job_id' => isset($input['job_id']) ? (int) $input['job_id'] : null, 'note' => $input['note'] ?? null];
    }

    private function jobs(?int $current = null): array
    {
        return MenuOption::of('staff_job')->where(fn ($q) => $q->where('active', true)->orWhere('id', $current))->get()->map->only('id', 'name')->all();
    }
}

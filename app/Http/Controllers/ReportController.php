<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\AttendanceExtra;
use App\Models\GuestMeal;
use App\Models\GuestMealExtra;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\StockMovement;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\MonthSummary;
use App\Support\Csv;
use App\Support\Quantity;
use App\Support\Rupees;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class ReportController extends Controller
{
    use ListsRecords;

    const KINDS = ['own' => 0, 'card' => 0, 'walk_in' => 0];

    public function index()
    {
        return Inertia::render('Reports/Index');
    }

    public function summary(Request $request, MonthSummary $summary)
    {
        return Inertia::render('Reports/Summary', $this->summaryOf($request, $summary));
    }

    public function summaryDownload(Request $request, MonthSummary $summary)
    {
        [$now, $before] = $this->summaryOf($request, $summary)['months'];
        $show = fn (?int $amount) => $amount === null ? 'Not available' : Rupees::show($amount);
        $rows = [];
        foreach (['left' => 'Money in and out', 'right' => 'Cost of running'] as $part => $title) {
            foreach ($now[$part] as $index => $line) {
                $rows[] = [$title, $line['label'], $show($line['amount']), $show($before[$part][$index]['amount'])];
            }
            [$key, $label] = $part === 'left' ? ['left_over', 'Money left over'] : ['profit', 'Estimated profit'];
            $rows[] = [$title, $label, $show($now[$key]), $show($before[$key])];
        }
        $rows[] = ['Meals', 'Meals served', $now['served'], $before['served']];
        $rows[] = ['Meals', 'Food cost per meal', $show($now['cost_per_meal']), $show($before['cost_per_meal'])];

        return Csv::download("summary_{$now['month']}.csv", ['Part', 'Line', $now['month'], $before['month']], $rows);
    }

    private function summaryOf(Request $request, MonthSummary $summary): array
    {
        $valid = Validator::make($request->only('month'), ['month' => ['bail', 'required', 'string', 'date_format:Y-m', 'before_or_equal:' . today()->format('Y-m')]])->passes();
        $month = $valid ? $request->input('month') : today()->format('Y-m');

        return ['months' => $summary->for([$month, Carbon::parse("$month-01")->subMonth()->format('Y-m')])];
    }

    public function due(Request $request)
    {
        return Inertia::render('Reports/Due', $this->dueOf($request));
    }

    public function dueDownload(Request $request)
    {
        return Csv::download('money_due_' . today()->toDateString() . '.csv', ['Name', 'Canteen ID', 'College', 'Due', 'Last payment', 'Last meal', 'Left or deleted'],
            array_map(fn ($row) => [Csv::cell($row['name']), Csv::cell($row['canteen_id']), Csv::cell($row['college']), $row['due'], $row['last_payment'], $row['last_meal'], $row['tag']],
                $this->dueOf($request)['rows']));
    }

    private function dueOf(Request $request): array
    {
        $filters = $this->listParams($request, ['name', 'due', 'last_payment'], 'name');
        $query = Payment::join('users', 'users.id', '=', 'payments.student_id')->whereRaw(Payment::BALANCE . ' > 0')
            ->select('payments.*', 'users.name', 'users.middle_name', 'users.last_name', 'users.canteen_id', 'users.collage_name', 'users.left_on', 'users.deleted_at')
            ->addSelect([
                'last_payment' => PaymentHistory::active()->whereColumn('student_id', 'payments.student_id')->selectRaw('max(payment_date)'),
                'last_own' => StudentAttendance::whereColumn('student_id', 'payments.student_id')->selectRaw('max(date)'),
                'last_guest' => GuestMeal::notCancelled()->whereColumn('student_id', 'payments.student_id')->selectRaw('max(date)'),
            ]);
        $colleges = (clone $query)->toBase()->get()->pluck('collage_name')->filter()->unique()->sort()->values();
        $college = $this->text($request, 'college');
        $filters['college'] = $colleges->contains($college) ? $college : '';
        $this->searchIn($query, ['users.name', 'users.middle_name', 'users.last_name', 'users.canteen_id'], $filters['search']);

        $rows = $query->when($filters['college'] !== '', fn ($q) => $q->where('users.collage_name', $filters['college']))->get()->map(fn ($row) => [
            'id' => $row->student_id, 'name' => str("{$row->name} {$row->middle_name} {$row->last_name}")->squish()->value(), 'canteen_id' => $row->canteen_id,
            'college' => $row->collage_name, 'due' => $row->balance, 'last_payment' => $row->last_payment, 'last_meal' => max($row->last_own, $row->last_guest),
            'tag' => $row->deleted_at !== null ? 'Deleted' : ($row->left_on !== null ? 'Left' : null),
        ])->sortBy([[fn ($a, $b) => ($filters['dir'] === 'desc' ? -1 : 1) * (is_string($a[$filters['sort']]) || is_string($b[$filters['sort']])
            ? strcmp(mb_strtolower((string) $a[$filters['sort']]), mb_strtolower((string) $b[$filters['sort']])) : $a[$filters['sort']] <=> $b[$filters['sort']])], ['id', 'asc']])->values();

        return [
            'rows' => $rows->all(), 'filters' => $filters, 'colleges' => $colleges, 'total' => $rows->sum('due'),
            'deleted' => $rows->where('tag', 'Deleted')->sum('due'),
        ];
    }

    public function meals(Request $request)
    {
        return Inertia::render('Reports/Meals', $this->mealsOf($request));
    }

    public function mealsDownload(Request $request)
    {
        $report = $this->mealsOf($request);
        $rows = [];
        foreach (['days' => 'Day', 'meals' => 'Meal', 'colleges' => 'College'] as $list => $part) {
            foreach ($report[$list] as $row) {
                $rows[] = [$part, Csv::cell($row['name']), $row['own'], $row['card'], $row['walk_in'], ''];
            }
        }
        foreach ($report['extras'] as $row) {
            $rows[] = ['Extra', Csv::cell($row['name']), $row['quantity'], '', '', $row['amount']];
        }
        foreach ($report['waste'] as $row) {
            $rows[] = ['Waste', Csv::cell("{$row['reason']}: {$row['item']}"), Quantity::show($row['quantity']) . " {$row['unit']}", '', '', Rupees::show($row['cost'])];
        }

        return Csv::download("meals_{$report['filters']['from']}_{$report['filters']['to']}.csv", ['Part', 'Name', 'Students or quantity', 'Guests on cards', 'Walk-ins', 'Amount'], $rows);
    }

    private function mealsOf(Request $request): array
    {
        [$from, $to, $message] = $this->reportDates($request);
        $names = MealPrice::pluck('name', 'id');
        $colleges = User::withTrashed()->pluck('collage_name', 'id');
        $college = fn ($id) => $colleges[$id] ?? null ?: 'No college';

        $own = StudentAttendance::whereBetween('date', [$from, $to])->groupBy('date', 'meal_id', 'student_id')
            ->selectRaw('date, meal_id, student_id, count(*) as many')->toBase()->get()
            ->map(fn ($row) => ['day' => $row->date, 'meal' => $names[$row->meal_id] ?? 'Removed meal', 'college' => $college($row->student_id), 'kind' => 'own', 'many' => (int) $row->many]);
        $guests = GuestMeal::notCancelled()->whereBetween('date', [$from, $to])->get()
            ->map(fn ($row) => ['day' => $row->date, 'meal' => $row->meal_name, 'college' => $row->isCard() ? $college($row->student_id) : 'Walk-ins', 'kind' => $row->type, 'many' => $row->plates]);
        $all = $own->concat($guests->all());

        $inRange = fn ($q) => $q->whereBetween('date', [$from, $to]);
        $extras = AttendanceExtra::whereIn('attendance_id', $inRange(StudentAttendance::query())->select('id'))->toBase()->get(['name', 'quantity', 'price'])
            ->concat(GuestMealExtra::whereIn('guest_meal_id', $inRange(GuestMeal::notCancelled())->select('id'))->toBase()->get(['name', 'quantity', 'price']))
            ->groupBy('name')->map(fn ($rows, $name) => [
                'name' => (string) $name, 'quantity' => (int) $rows->sum('quantity'), 'amount' => (int) $rows->sum(fn ($row) => $row->quantity * $row->price),
            ])->sortKeys()->values();
        $waste = StockMovement::whereHas('entry', fn ($q) => $inRange($q->notCancelled()->where('type', 'waste')))->with('item', 'entry.reason')->get()
            ->groupBy(fn ($row) => "{$row->entry->reason_id}|{$row->stock_item_id}")->map(fn ($rows) => [
                'reason' => $rows[0]->entry->reason->name, 'item' => $rows[0]->item->name, 'unit' => $rows[0]->item->unit,
                'quantity' => -$rows->sum('quantity'), 'cost' => -$rows->sum('cost'),
            ])->sortBy([['reason', 'asc'], ['item', 'asc']])->values();

        return [
            'filters' => compact('from', 'to'), 'message' => $message,
            'days' => $this->grouped($all, 'day'), 'meals' => $this->grouped($all, 'meal'), 'colleges' => $this->grouped($all, 'college'),
            'totals' => array_replace(self::KINDS, $all->groupBy('kind')->map->sum('many')->all()),
            'extras' => $extras, 'waste' => $waste,
        ];
    }

    private function grouped(Collection $rows, string $by): array
    {
        return $rows->groupBy($by)->sortKeys()->map(fn ($group, $name) => ['name' => (string) $name]
            + array_replace(self::KINDS, $group->groupBy('kind')->map->sum('many')->all()))->values()->all();
    }
}

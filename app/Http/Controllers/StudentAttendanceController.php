<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Models\StudentAttendance;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\User;
use App\Services\BillingService;
use App\Support\Csv;
use App\Services\MenuCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;


class StudentAttendanceController extends Controller
{
    use ListsRecords;

    private function validationRules(): array
    {
        return [
            'user_id' => ['bail', 'required', 'integer', Rule::exists('users', 'id')->where('role', 'user')->whereNull('deleted_at')],
            'meal_id' => ['bail', 'required', 'integer', 'exists:meal_prices,id'],
            'include_extra' => ['required', 'in:yes,no'],
            'extra_items' => ['nullable', 'array'],
            'extra_items.*' => ['nullable', 'integer', 'between:0,99'],
            'date' => ['bail', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name', 'canteen_id', 'collage_name'], 'name');
        $today = today()->toDateString();
        $meals = MealPrice::offeredOn($today);
        $mealId = $meals->firstWhere('id', (int) $this->text($request, 'meal'))['id'] ?? null;

        $query = User::active()->orderBy($filters['sort'], $filters['dir'])->orderBy('id');
        $this->searchIn($query, ['name', 'middle_name', 'last_name', 'canteen_id', 'collage_name'], $filters['search']);
        $students = $this->pageOf($query);

        $marked = $mealId === null ? collect() : StudentAttendance::where('meal_id', $mealId)
            ->where('date', $today)
            ->whereIn('student_id', $students->pluck('id'))
            ->pluck('student_id');

        return Inertia::render('Attendance/Index', [
            'meals' => $meals,
            'meal' => $mealId,
            'menu' => MenuCalendar::between($today, $today)[$today],
            'students' => $students->through(fn ($user) => $user->only(
                'id', 'name', 'middle_name', 'last_name', 'canteen_id', 'collage_name', 'profile_pic'
            ) + ['marked' => $mealId === null ? null : $marked->contains($user->id)]),
            'filters' => $filters + ['meal' => $mealId],
            'range' => $this->dates($request),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request, string $id)
    {
        $valid = Validator::make($request->only('date'), ['date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today']])->passes();
        $date = $valid ? $request->input('date') : today()->toDateString();

        return Inertia::render('Attendance/Create', [
            'student' => User::findOrFail($id)->only('id', 'name', 'middle_name', 'last_name', 'canteen_id'),
            'meals' => MealPrice::offeredOn($date),
            'menu' => MenuCalendar::between($date, $date)[$date],
            'date' => $date,
            'extras' => Menus::extras()->get(['id', 'name', 'price']),
            'today' => today()->toDateString(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, BillingService $billing)
    {
        $input = $request->validate($this->validationRules());
        $extras = [];
        if ($input['include_extra'] === 'yes') {
            foreach ($input['extra_items'] ?? [] as $id => $quantity) {
                $extras[(int) $id] = (int) $quantity;
            }
        }

        try {
            $attendance = $billing->markAttendance(
                (int) $input['user_id'], (int) $input['meal_id'], $input['date'] ?? today()->toDateString(), $extras
            );
        } catch (BillingException $e) {
            return $this->rejected($request, $e->getMessage());
        }

        return $this->backToList($request)
            ->with('success', "{$attendance->meal->name} marked for {$attendance->user->full_name}.");
    }

    private function rejected(Request $request, string $message)
    {
        return ($request->input('return_to') === 'list' ? $this->backToList($request) : redirect()->back())
            ->with('error', $message);
    }

    // Built from checked values, never from a URL sent by the browser.
    private function backToList(Request $request)
    {
        if ($request->input('return_to') !== 'list') {
            return redirect()->route('student-attendance.index');
        }

        $filters = $this->listParams($request, ['name', 'canteen_id', 'collage_name'], 'name');
        $page = (int) (is_scalar($request->input('page')) ? $request->input('page') : 0);

        return redirect()->route('student-attendance.index', array_filter([
            'meal' => (int) $request->input('meal_id'),
            'search' => $filters['search'],
            'sort' => $filters['sort'] === 'name' ? null : $filters['sort'],
            'dir' => $filters['dir'] === 'asc' ? null : $filters['dir'],
            'page' => $page,
        ]));
    }

    private function backToQuick(Request $request)
    {
        $meal = $request->input('meal_id');

        return redirect()->route('attendance.quick', array_filter(['meal' => is_scalar($meal) ? MealPrice::active()->whereKey((int) $meal)->value('id') : null]));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $student = User::withTrashed()->findOrFail($id);
        $filters = $this->listParams($request, ['date'], 'date', 'desc') + $this->dates($request);

        $query = StudentAttendance::with('meal', 'extras')->where('student_id', $id)
            ->whereBetween('date', [$filters['from'], $filters['to']])
            ->orderBy('date', $filters['dir'])->orderBy('id', $filters['dir']);

        if ($filters['search'] !== '') {
            $query->whereHas('meal', fn ($q) => $this->searchIn($q, ['name'], $filters['search']));
        }

        $menuNames = Menus::pluck('name', 'id');

        return Inertia::render('Attendance/Show', [
            'student' => $student->only('id', 'name', 'middle_name', 'last_name', 'canteen_id'),
            'canMark' => !$student->trashed() && $student->left_on === null,
            'rows' => $this->pageOf($query)->through(fn ($row) => [
                'id' => $row->id,
                'date' => $row->date,
                'meal' => $row->meal->name ?? 'Removed meal',
                'amount' => $row->amount,
                'extra_amount' => $row->extra_amount,
                'extras' => $row->extraItems($menuNames),
                'is_paid' => $row->is_paid == 1,
            ]),
            'guests' => GuestMeal::with('extras')->where('student_id', $id)->whereBetween('date', [$filters['from'], $filters['to']])
                ->orderByDesc('date')->orderByDesc('id')->get()->map->row(),
            'filters' => $filters,
            'today' => today()->toDateString(),
        ]);
    }

    public function downloadUserHistory(Request $request)
    {
        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return $this->csv($request, StudentAttendance::where('student_id', $request->user_id), GuestMeal::where('student_id', $request->user_id), 'attendance_' . (int) $request->user_id);
    }

    public function download(Request $request)
    {
        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->csv($request, StudentAttendance::query(), GuestMeal::query(), 'attendance');
    }

    private function csv(Request $request, $query, $guests, string $name)
    {
        $attendances = $query->select(['id', 'student_id', 'meal_id', 'amount', 'extra_amount', 'extra_meal_id', 'date'])
            ->with([
                'extras',
                'user' => function ($query) {
                    $query->select(['id', 'name', 'middle_name', 'last_name', 'collage_name', 'canteen_id']);
                },
                'meal' => function ($query) {
                    $query->select(['id', 'name']);
                }
            ])
            ->whereBetween('date', [$request->from, $request->to])
            ->get();
        $menuNames = Menus::pluck('name', 'id');
        $guests = $guests->notCancelled()->with('student', 'extras')->whereBetween('date', [$request->from, $request->to])->orderBy('id')->get();

        // A stable sort: own meals first, then the guests of the same date.
        $rows = $attendances->map(fn ($attendance) => [
            'name' => $attendance->user->full_name ?? 'N/A',
            'canteen_id' => $attendance->user->canteen_id ?? 'N/A',
            'date' => $attendance->date,
            'meal' => $attendance->meal->name ?? 'N/A',
            'amount' => $attendance->amount,
            'extras' => $attendance->extraItems($menuNames),
            'extra_amount' => $attendance->extra_amount,
        ])->toBase()->concat($guests->map(fn ($guest) => [
            'name' => $guest->isCard() ? $guest->student->full_name : 'Walk-in guest',
            'canteen_id' => $guest->student->canteen_id ?? 'N/A',
            'date' => $guest->date,
            'meal' => "{$guest->meal_name} (guest x {$guest->plates})",
            'amount' => $guest->plates * $guest->price,
            'extras' => $guest->row()['extras'],
            'extra_amount' => $guest->extra_amount,
        ]))->sortBy('date');

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $name . '_' . now()->format('Ymd') . '.csv"',
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');

            // CSV Column headings
            fputcsv($file, [
                'Sr No.',
                'Full Name',
                'Canteen ID',
                'Date',
                'Meal Name',
                'Amount',
                'Extra Items',
                'Extra Amount',
            ]);

            $srNo = 1;

            foreach ($rows as $row) {
                $extraItemsString = implode(', ', array_map(
                    fn ($item) => $item['name'] . ($item['quantity'] === null ? '' : " x {$item['quantity']}"), $row['extras']
                )) ?: 'None';

                // Write CSV row
                fputcsv($file, [
                    $srNo,
                    Csv::cell($row['name']),
                    Csv::cell($row['canteen_id']),
                    $row['date'],
                    Csv::cell($row['meal']),
                    number_format((float) $row['amount'], 2),
                    Csv::cell($extraItemsString),
                    number_format((float) $row['extra_amount'], 2),
                ]);

                $srNo++;
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function deleteSpecificAttedance(Request $request, BillingService $billing)
    {
        // A list would be read as entry 1.
        $id = is_array($request->input('id')) ? 0 : $request->integer('id');

        try {
            $studentAttendance = $billing->deleteAttendance($id);
        } catch (BillingException $e) {
            if ($request->input('return_to') === 'quick') {
                return $this->backToQuick($request)->with('error', $e->getMessage());
            }
            $entry = StudentAttendance::find($id);

            return ($entry?->student_id
                ? redirect()->route('student-attendance.show', ['id' => $entry->student_id] + $this->dates($request))
                : redirect()->route('student-attendance.index'))->with('error', $e->getMessage());
        }

        $meal = $studentAttendance->meal->name ?? 'Removed meal';
        $date = Carbon::parse($studentAttendance->date)->format('j F Y');
        $redirect = $request->input('return_to') === 'quick' ? $this->backToQuick($request)
            : redirect()->route('student-attendance.show', ['id' => $studentAttendance->student_id] + $this->dates($request));

        return $redirect->with('success', "$meal on $date deleted.");
    }
}

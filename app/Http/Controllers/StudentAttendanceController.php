<?php

namespace App\Http\Controllers;

use App\Models\StudentAttendance;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;


class StudentAttendanceController extends Controller
{
    use ListsRecords;

    protected $validationRules = [
        'user_id' => ['required', 'integer', 'exists:users,id'],
        'meal_id' => ['required', 'integer', 'exists:meal_prices,id'],
        'include_extra' => ['required', 'in:yes,no'],
        'extra_items' => ['nullable', 'array'],
        'extra_items.*' => ['nullable', 'integer', 'min:0'],
        'date' => ['nullable', 'date'],

    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name', 'canteen_id', 'collage_name'], 'name');
        $meals = MealPrice::get(['id', 'name', 'price']);
        $mealId = $meals->firstWhere('id', (int) $this->text($request, 'meal'))?->id;

        $query = User::where('role', 'user')->orderBy($filters['sort'], $filters['dir'])->orderBy('id');
        $this->searchIn($query, ['name', 'middle_name', 'last_name', 'canteen_id', 'collage_name'], $filters['search']);
        $students = $this->pageOf($query);

        $marked = $mealId === null ? collect() : StudentAttendance::where('meal_id', $mealId)
            ->where('date', today()->toDateString())
            ->whereIn('student_id', $students->pluck('id'))
            ->pluck('student_id');

        return Inertia::render('Attendance/Index', [
            'meals' => $meals,
            'meal' => $mealId,
            'students' => $students->through(fn ($user) => $user->only(
                'id', 'name', 'middle_name', 'last_name', 'canteen_id', 'collage_name', 'profile_pic'
            ) + ['marked' => $mealId === null ? null : $marked->contains($user->id)]),
            'filters' => $filters + ['meal' => $mealId],
            'range' => $this->dates($request),
        ]);
    }

    // Dates from the address bar; anything invalid falls back to the last 30 days.
    private function dates(Request $request): array
    {
        $valid = Validator::make($request->only('from', 'to'), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ])->passes();

        return $valid ? $request->only('from', 'to') : [
            'from' => today()->subDays(29)->toDateString(),
            'to' => today()->toDateString(),
        ];
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(string $id)
    {
        return Inertia::render('Attendance/Create', [
            'student' => User::findOrFail($id)->only('id', 'name', 'middle_name', 'last_name', 'canteen_id'),
            'meals' => MealPrice::get(['id', 'name', 'price']),
            'extras' => Menus::where('is_extra', '1')->get(['id', 'name', 'slug', 'price']),
            'today' => today()->toDateString(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // checking user exists or not..
        // checking meal is exists or not..

        $input = $request->all();
        $validator = Validator::make($input, $this->validationRules);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $mealName = MealPrice::find($input['meal_id'])->name;
        $studentName = Str::squish(User::withTrashed()->find($input['user_id'])->full_name);

        $attendanceDate = isset($input['date']) ? $input['date'] : now()->format('Y-m-d');

        // checking payment already done for selected date 
        $checkingPaymentAlreadyDone = StudentAttendance::select('date')->where('student_id', $input['user_id'])->where('is_paid',1)->orderBy('date','DESC')->first();
        if(!empty($checkingPaymentAlreadyDone)){
            if($checkingPaymentAlreadyDone->date != null){
                $lastPaymentDate   = Carbon::parse($checkingPaymentAlreadyDone->date); 
                $newAttedanceDate = Carbon::parse($attendanceDate);
                $resultEndDate = $newAttedanceDate->lt($lastPaymentDate);
                if($resultEndDate){
                    return $this->rejected($request, 'This date is already paid, so attendance cannot be added.');
                }
            }

        }
        
        // checking attendance already done for student or not for selected date
        $checkingAlreadyAttendaceThere = StudentAttendance::where('student_id', $input['user_id'])->where('date',$attendanceDate)->where('meal_id',$input['meal_id'])->exists();
        
        if($checkingAlreadyAttendaceThere){
            return $this->rejected($request, "$mealName is already marked for this date.");
        }

        // get the meal price using selected meal id
        $mealPrice = MealPrice::select('price')->where('id', $input['meal_id'])->first();
        $mealValue = $mealPrice->price;
        $extraValue = 0;
        $extraItemIdArray = [];

        // if extra items there then add there price also
        if (isset($input['include_extra']) && $input['include_extra'] === 'yes') {
            $extraIteamArray = $input['extra_items'] ?? [];
            $slugArray = array_keys($extraIteamArray);

            $extraItemPriceArray = Menus::select('id', 'slug', 'price')->whereIn('slug', $slugArray)->get();

            if (!empty($extraItemPriceArray)) {
                foreach ($extraItemPriceArray as $item) {
                    $quantity = (int) ($extraIteamArray[$item->slug] ?? 0);
                    if ($quantity > 0) {
                        $amount = $quantity * $item->price;
                        if ($amount > 0) {
                            $extraValue += $amount;
                            $extraItemIdArray[] = $item->id;
                        }
                    }
                }
            }
        }

        $data = [
            'student_id' => $input['user_id'],
            'meal_id'    => $input['meal_id'],
            'amount'     => $mealValue,             // keep as float/decimal if DB is decimal
            'extra_amount'  => $extraValue,
            'extra_meal_id' => !empty($extraItemIdArray) ? json_encode($extraItemIdArray) : null,
            'date' => $attendanceDate
        ];
        $addAttendance = StudentAttendance::create($data);

        $paymentAmount = 0;
        $payment = Payment::where('student_id', $input['user_id'])->first();

        if (!empty($payment)) {

            $paymentAmount = $payment->amount ?? 0;
            $totalAmount   = $paymentAmount + $mealValue + $extraValue;
            $paymentId     = $payment->id;
            $paymentEndDate    = $payment->end_date;
            $paymentStartDate  = $payment->start_date;

            $inputDate      = Carbon::parse($attendanceDate); 
            $storeEndDate   = Carbon::parse($paymentEndDate); 
            $storeStartDate = Carbon::parse($paymentStartDate);

            $resultEndDate = $inputDate->gt($storeEndDate);

            if($resultEndDate){
                $newDateEndDate = $attendanceDate;
            }
            else{
                $newDateEndDate = $paymentEndDate;
            }

            $resultStartDate = $inputDate->lt($storeStartDate);

            if($resultStartDate){
                $newStartDate = $attendanceDate;
            }
            else{
                $newStartDate = $storeStartDate;
            }

            $paymentAmountUpdate = Payment::find($paymentId)
                ->update([
                    'amount' => $totalAmount,
                    'start_date' => $newStartDate,
                    'end_date'   => $newDateEndDate,
                ]);

        } else {

            $totalAmount   = $paymentAmount + $mealValue + $extraValue;

            $paymentCreate = Payment::create([
                'amount' => $totalAmount,
                'student_id' => $input['user_id'],
                'start_date' => $attendanceDate,
                'end_date'   => $attendanceDate,
            ]);

        }


        return $this->backToList($request)->with('success', "$mealName marked for $studentName.");
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

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $student = User::withTrashed()->findOrFail($id);
        $filters = $this->listParams($request, ['date'], 'date', 'desc') + $this->dates($request);

        $query = StudentAttendance::with('meal')->where('student_id', $id)
            ->whereBetween('date', [$filters['from'], $filters['to']])
            ->orderBy('date', $filters['dir'])->orderBy('id', $filters['dir']);

        if ($filters['search'] !== '') {
            $query->whereHas('meal', fn ($q) => $this->searchIn($q, ['name'], $filters['search']));
        }

        return Inertia::render('Attendance/Show', [
            'student' => $student->only('id', 'name', 'middle_name', 'last_name', 'canteen_id'),
            'canMark' => !$student->trashed(),
            'rows' => $this->pageOf($query)->through(fn ($row) => [
                'id' => $row->id,
                'date' => $row->date,
                'meal' => $row->meal->name ?? 'Removed meal',
                'amount' => $row->amount,
                'extra_amount' => $row->extra_amount,
                'is_paid' => $row->is_paid == 1,
            ]),
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

        return $this->csv($request, StudentAttendance::where('student_id', $request->user_id), 'attendance_' . (int) $request->user_id);
    }

    public function download(Request $request)
    {
        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->csv($request, StudentAttendance::query(), 'attendance');
    }

    private function csv(Request $request, $query, string $name)
    {
        $attendances = $query->select(['id', 'student_id', 'meal_id', 'amount', 'extra_amount', 'extra_meal_id', 'date'])
            ->with([
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

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $name . '_' . now()->format('Ymd') . '.csv"',
        ];

        $callback = function () use ($attendances, $menuNames) {
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

            foreach ($attendances as $attendance) {
                // Decode extra_meal_id JSON to array of IDs
                $extraMealIds = json_decode($attendance->extra_meal_id ?? '', true) ?: [];
                $extraItemsString = $menuNames->only($extraMealIds)->implode(', ') ?: 'None';

                // Write CSV row
                fputcsv($file, [
                    $srNo,
                    $attendance->user->full_name ?? 'N/A',
                    $attendance->user->canteen_id ?? 'N/A',
                    $attendance->date,
                    $attendance->meal->name ?? 'N/A',
                    number_format((float) $attendance->amount, 2),
                    $extraItemsString,
                    number_format((float) $attendance->extra_amount, 2),
                ]);

                $srNo++;
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function deleteSpecificAttedance(Request $request)
    {
        $studentAttendance = StudentAttendance::with('meal')->find($request->integer('id'));

        if (!$studentAttendance) {
            return redirect()->route('student-attendance.index')->with('error', 'This attendance entry was already deleted.');
        }

        $totalAmount = (int)$studentAttendance->amount + (int)$studentAttendance->extra_amount;
        $studentId = $studentAttendance->student_id;

        $studentPaymentData = Payment::where('student_id', $studentId)->first();

        if ($studentPaymentData) {
            $newPaymentAmount = $studentPaymentData->amount - $totalAmount;

            // Update payment amount
            $studentPaymentData->update(['amount' => $newPaymentAmount]);
        }

        // Delete the attendance record
        $studentAttendance->delete();

        $meal = $studentAttendance->meal->name ?? 'Removed meal';
        $date = Carbon::parse($studentAttendance->date)->format('j F Y');
        $redirect = $studentId
            ? redirect()->route('student-attendance.show', ['id' => $studentId] + $this->dates($request))
            : redirect()->route('student-attendance.index');

        return $redirect->with('success', "$meal on $date deleted.");
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\StudentAttendance;
use App\Http\Controllers\Controller;
use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\PaymentHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Response;


class StudentAttendanceController extends Controller
{
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
    public function index()
    {

        $mealData = MealPrice::select('id', 'name')->get();
        $data['meals'] = $mealData;
        return view('Attendance.index', $data);
    }

    public function list(Request $request)
    {

        $mealId = $request->meal_id ?? null;
        $columns = ['canteen_id', 'name', 'collage_name'];

        $length = $request->input('length');       // number of records per page
        $start = $request->input('start');         // offset

        $orderInput = $request->input('order');
        if (isset($orderInput[0]['column']) && isset($orderInput[0]['dir'])) {
            $orderColumnIndex = (int)$orderInput[0]['column'];
            $orderColumn = $columns[$orderColumnIndex];
            $orderDir = $orderInput[0]['dir'] === 'desc' ? 'desc' : 'asc';
        } else {
            // Default order
            $orderColumn = 'id';
            $orderDir = 'asc';
        }
        $search = $request->input('search.value'); // search input value

        // Base query
        $query = User::select('id', 'canteen_id', 'name', 'middle_name', 'last_name', 'collage_name');
        $query = $query->whereNot('name', 'admin');

        // Apply search filter if not empty
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('canteen_id', 'like', "%{$search}%")
                    ->orWhere('collage_name', 'like', "%{$search}%");
            });
        }

        $totalFiltered = $query->count();

        // Apply ordering, pagination
        $users = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($length)
            ->get();

        if ($orderDir === 'asc') {
            $counter = $start + 1;
        } else {
            $counter = $totalFiltered - $start;
        }

        $data = [];
        if (!empty($users)) {
            foreach ($users as $user) {

                $today = Carbon::today()->toDateString(); // e.g. '2025-08-17'

                $todayAttendanceExists = StudentAttendance::where('student_id', $user->id)
                    ->where('date', $today)
                    ->where('meal_id', (int) $mealId)->exists();


                $action = view('Attendance.list_actions', ['id' => $user->id, 'meal_id' => $mealId, 'already_there' => $todayAttendanceExists])->render();

                $nestedData = [];
                $nestedData['canteen_id'] = $user->canteen_id;
                $nestedData['name'] = $user->full_name;
                $nestedData['canteen_id'] = $user->canteen_id;
                $nestedData['collage_name'] = $user->collage_name;
                $nestedData['action'] = $action;
                $data[] = $nestedData;
                if ($orderDir === 'asc') {
                    $counter++;
                } else {
                    $counter--;
                }
            }
        }

        $json_data = [
            "draw" => intval($request->input('draw')), // for security
            "recordsTotal" => User::count(),
            "recordsFiltered" => $totalFiltered,
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    public function specificUserList(Request $request)
    {

        $id = $request->input('user_id');
        $columns = ['id', 'name', 'collage_name'];

        $length = $request->input('length');       // number of records per page
        $start = $request->input('start');         // offset

        $orderInput = $request->input('order');
        if (isset($orderInput[0]['column']) && isset($orderInput[0]['dir'])) {
            $orderColumnIndex = (int)$orderInput[0]['column'];
            $orderColumn = $columns[$orderColumnIndex];
            $orderDir = $orderInput[0]['dir'] === 'desc' ? 'desc' : 'asc';
        } else {
            // Default order
            $orderColumn = 'id';
            $orderDir = 'asc';
        }
        $search = $request->input('search.value'); // search input value


        $datesRange = $request->input('dates');
        $userId = $request->user_id;
        $dates = explode(' - ', $datesRange);

        // Defensive coding: assign start and end default to today if parsing fails
        $startDateString = $dates[0] ?? now()->toDateString();
        $endDateString = $dates[1] ?? $startDateString;  // if no end date, assume same as start


        $startDateCarbon = Carbon::parse($startDateString);
        $endDateCarbon = Carbon::parse($endDateString);

        $startDate = $startDateCarbon->toDateString();
        $endDate   = $endDateCarbon->toDateString();

        // Base query
        $query = StudentAttendance::with(['meal'])->where('student_id', $id)
                                     ->whereBetween('date', [$startDate, $endDate]);


        // Apply search filter if not empty
        if (!empty($search)) {

            $query->where(function ($q) use ($search) {
                $q->where('amount', 'like', "%{$search}%")
                    ->where('extra_amount', 'like', "%{$search}%")
                    ->where('date', 'like', "%{$search}%")
                    ->orWhereHas('meal', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $totalFiltered = $query->count();

        // Apply ordering, pagination
        $attendances = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($length)
            ->get();

        if ($orderDir === 'asc') {
            $counter = $start + 1;
        } else {
            $counter = $totalFiltered - $start;
        }

        $data = [];
        if (!empty($attendances)) {
            foreach ($attendances as $attendance) {

                $nestedData = [];
                $nestedData['id'] = $attendance->id;
                $nestedData['date'] = $attendance->date;
                $nestedData['meal_name'] = $attendance->meal->name;
                $nestedData['amount'] = $attendance->amount;
                $nestedData['extra_amount'] = $attendance->extra_amount;
                $nestedData['action'] = ($attendance->is_paid == 0) ?"Heloo" :"Done" ;
                $data[] = $nestedData;
                if ($orderDir === 'asc') {
                    $counter++;
                } else {
                    $counter--;
                }
            }
        }

        $json_data = [
            "draw" => intval($request->input('draw')), // for security
            "recordsTotal" =>0,
            "recordsFiltered" => $totalFiltered,
            "data" => $data,
        ];

        return response()->json($json_data);
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create(string $id)
    {
        $userData = User::find($id);
        
        // $today = Carbon::today()->toDateString();
        // $todayAttendanceDoneMealIds = StudentAttendance::where('student_id', $id)->where('date', $today)->pluck('meal_id');
        $mealData =  MealPrice::get();

        // if (count($todayAttendanceDoneMealIds) > 0) {
        //     $mealDataQuery = $mealDataQuery->whereNotIn('id', $todayAttendanceDoneMealIds);
        // }

        $extraItems = Menus::where('is_extra', '1')->get();

        $data['user']         = $userData;
        $data['meal_data']    = $mealData;
        $data['extra_items']  = $extraItems;

        return view('Attendance.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // checking user exists or not..
        // checking meal is exists or not..

        $input = $request->all();
        $isDirectAttedance = false;
        if (isset($request->is_direct)) {
            $isDirectAttedance = true;
        }

        $validator = Validator::make($input, $this->validationRules);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $attendanceDate = isset($input['date']) ? $input['date'] : now()->format('Y-m-d');


        $checkingPaymentAlreadyDone = StudentAttendance::select('date')->where('student_id', $input['user_id'])->where('is_paid',1)->orderBy('date','DESC')->first();
        if(!empty($checkingPaymentAlreadyDone)){
            if($checkingPaymentAlreadyDone->date != null){
                $lastPaymentDate   = Carbon::parse($checkingPaymentAlreadyDone->date); 
                $newAttedanceDate = Carbon::parse($attendanceDate);
                $resultEndDate = $lastPaymentDate->lt($newAttedanceDate);
                if($resultEndDate){
                    if ($isDirectAttedance) {
                        return response()->json(['message' => 'Payemnt Already Done for Selected Dates']);
                    }
                        return redirect()->back()->with('error', 'Payemnt Already Done for Selected Dates!');
                    }
            }

        }
        

        $checkingAlreadyAttendaceThere = StudentAttendance::where('student_id', $input['user_id'])->where('date',$attendanceDate)->where('meal_id',$input['meal_id'])->exists();
        
        if($checkingAlreadyAttendaceThere){
            if ($isDirectAttedance) {
                return response()->json(['message' => 'Attendance Already Done!']);
            }
            return redirect()->back()->with('error', 'Attendance Already Done!');
        }

        $mealPrice = MealPrice::select('price')->where('id', $input['meal_id'])->first();
        $mealValue = $mealPrice->price;
        $extraValue = 0;
        $extraItemIdArray = [];

        if (isset($input['include_extra']) && $input['include_extra'] === 'yes') {
            $extraIteamArray = $input['extra_items'];
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
            'meal_id' => $input['meal_id'],
            'amount' => $mealValue,             // keep as float/decimal if DB is decimal
            'extra_amount' => $extraValue,
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


        if ($addAttendance) {
            if ($isDirectAttedance) {
                return response()->json(['message' => 'Attendance added successfully.']);
            }
            return redirect()->route('student-attendance.index')->with('success', 'Attendance Added successfully!');
        } else {
            if ($isDirectAttedance) {
                return response()->json(['message' => 'Something went wrong please try again later!.']);
            }
            return redirect()->route('student-attendance.index')->with('error', 'Something went wrong please try again later!');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $userData = User::find($id);
        $today = Carbon::today()->toDateString();
        $todayMeal = StudentAttendance::where('student_id', $id)->where('date', $today)->get();
        $data['meal_data'] = $todayMeal;
        $data['user'] = $userData;
        return view('Attendance.show', $data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(StudentAttendance $studentAttendance)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, StudentAttendance $studentAttendance)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StudentAttendance $studentAttendance)
    {
        //
    }

    public function downloadUserHistory(Request $request)
    {
        $request->validate([
            'dates' => ['required', 'string'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $datesRange = $request->input('dates');
        $userId = $request->user_id;
        $dates = explode(' - ', $datesRange);

        // Defensive coding: assign start and end default to today if parsing fails
        $startDateString = $dates[0] ?? now()->toDateString();
        $endDateString = $dates[1] ?? $startDateString;  // if no end date, assume same as start

        $userId = $request->input('user_id');

        $startDateCarbon = Carbon::parse($startDateString);
        $endDateCarbon = Carbon::parse($endDateString);

        $startDate = $startDateCarbon->toDateString();
        $endDate   = $endDateCarbon->toDateString();

        $attendances = StudentAttendance::select(['id', 'student_id', 'meal_id', 'amount', 'extra_amount', 'extra_meal_id', 'date'])
            ->with([
                'user' => function ($query) {
                    $query->select(['id', 'name', 'middle_name', 'last_name', 'collage_name', 'canteen_id']);
                },
                'meal' => function ($query) {
                    $query->select(['id', 'name']);
                }
            ])
            ->where('student_id', $userId)
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="attendance_' . $userId . '_' . now()->format('Ymd') . '.csv"',
        ];

        $callback = function () use ($attendances) {
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
                // Basic user & meal info with safe null checks
                $fullName  = $attendance->user->full_name ?? 'N/A';
                $canteenId = $attendance->user->canteen_id ?? 'N/A';
                $mealName  = $attendance->meal->name ?? 'N/A';
                $amount    = $attendance->amount;
                $date      = $attendance->date;

                $extraAmount = $attendance->extra_amount;

                // Decode extra_meal_id JSON to array of IDs
                $extraMealIds = @json_decode($attendance->extra_meal_id, true);
                $extraMealNames = [];

                if (is_array($extraMealIds) && !empty($extraMealIds)) {
                    // Fetch menu names matching extra IDs
                    $menus = Menus::whereIn('id', $extraMealIds)->pluck('name')->toArray();
                    $extraMealNames = $menus;
                }

                // Combine extra meal names separated by comma
                $extraItemsString = empty($extraMealNames) ? 'None' : implode(', ', $extraMealNames);

                // Write CSV row
                fputcsv($file, [
                    $srNo,
                    $fullName,
                    $canteenId,
                    $date,
                    $mealName,
                    number_format($amount, 2),
                    $extraItemsString,
                    number_format($extraAmount, 2),
                ]);

                $srNo++;
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function download(Request $request)
    {
        $request->validate([
            'dates' => ['required', 'string'],
        ]);

        $datesRange = $request->input('dates');
        $userId = $request->user_id;
        $dates = explode(' - ', $datesRange);

        // Defensive coding: assign start and end default to today if parsing fails
        $startDateString = $dates[0] ?? now()->toDateString();
        $endDateString = $dates[1] ?? $startDateString;  // if no end date, assume same as start


        $startDateCarbon = Carbon::parse($startDateString);
        $endDateCarbon = Carbon::parse($endDateString);

        $startDate = $startDateCarbon->toDateString();
        $endDate   = $endDateCarbon->toDateString();

        $attendances = StudentAttendance::select(['id', 'student_id', 'meal_id', 'amount', 'extra_amount', 'extra_meal_id', 'date'])
            ->with([
                'user' => function ($query) {
                    $query->select(['id', 'name', 'middle_name', 'last_name', 'collage_name', 'canteen_id']);
                },
                'meal' => function ($query) {
                    $query->select(['id', 'name']);
                }
            ])
            ->whereBetween('date', [$startDate, $endDate])
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="attendance_' . $userId . '_' . now()->format('Ymd') . '.csv"',
        ];

        $callback = function () use ($attendances) {
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
                // Basic user & meal info with safe null checks
                $fullName = $attendance->user->full_name ?? 'N/A';
                $canteenId = $attendance->user->canteen_id ?? 'N/A';
                $mealName = $attendance->meal->name ?? 'N/A';
                $amount = $attendance->amount;
                $date = $attendance->date;

                $extraAmount = $attendance->extra_amount;

                // Decode extra_meal_id JSON to array of IDs
                $extraMealIds = @json_decode($attendance->extra_meal_id, true);
                $extraMealNames = [];

                if (is_array($extraMealIds) && !empty($extraMealIds)) {
                    // Fetch menu names matching extra IDs
                    $menus = Menus::whereIn('id', $extraMealIds)->pluck('name')->toArray();
                    $extraMealNames = $menus;
                }

                // Combine extra meal names separated by comma
                $extraItemsString = empty($extraMealNames) ? 'None' : implode(', ', $extraMealNames);

                // Write CSV row
                fputcsv($file, [
                    $srNo,
                    $fullName,
                    $canteenId,
                    $date,
                    $mealName,
                    number_format($amount, 2),
                    $extraItemsString,
                    number_format($extraAmount, 2),
                ]);

                $srNo++;
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function addTodayAttedance(Request $request)
    {
        dd($request->all());
    }

    public function deleteSpecificAttedance(Request $request)
    {
        try {

            $attendanceId = $request->id;
            $studentAttendance = StudentAttendance::find($attendanceId);

            if (!$studentAttendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete Student Attedance. Please try again later.!',
                ], 500);
                // Handle not found, e.g. throw exception or return error response
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
            return response()->json([
                'success' => true,
                'message' => 'Student Attedance deleted successfully .',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete Student Attedance. Please try again later.',
                'error' => $e->getMessage(),  
            ], 500);
        }

        dd($request->all());
    }
}

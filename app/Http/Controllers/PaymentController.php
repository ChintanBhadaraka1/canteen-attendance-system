<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Http\Controllers\Controller;
use App\Models\MealPrice;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsValidator;



class PaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    protected $validationRules =[
        'amount' => ['required','numeric'],
        'type' =>  ['required', 'in:cash,upi'],
        'month' => ['required','in:January,February,March,April,May,June,July,August,September,October,November,December'],
    ];
    public function index()
    {
        return view('Bill.index');
    }

    public function list(Request $request)
    {

        $columns = ['id', 'users.name', 'users.canteen_id'];

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
        $query = Payment::select('id', 'student_id', 'amount','pending_amount','advance_amount')->with([
            'user' => function ($query) {
                $query->select(['id', 'name', 'middle_name', 'last_name', 'collage_name', 'canteen_id']);
            }
        ]);

        // Apply search filter if not empty
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('amount', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('middle_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('canteen_id', 'like', "%{$search}%")
                            ->orWhere('collage_name', 'like', "%{$search}%");
                    });
            });
        }



        $totalFiltered = $query->count();

        // Apply ordering, pagination
        $userPayments = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($length)
            ->get();

        if ($orderDir === 'asc') {
            $counter = $start + 1;
        } else {
            $counter = $totalFiltered - $start;
        }

        $data = [];
        if (!empty($userPayments)) {
            foreach ($userPayments as $payment) {
                $totalAmount = 0;
                $totalAmount = ($payment->amount + $payment->pending_amount)  - $payment->advance_amount;
                if($totalAmount < 0){
                    $amount = abs($totalAmount)."(Advance)";
                }
                else{
                    $amount = $totalAmount;
                }
                $action = view('Bill.list_action', ['id' => $payment->student_id])->render();
                $nestedData = [];
                $nestedData['sr_no'] = $counter;
                $nestedData['name'] = isset($payment->user) ? $payment->user->full_name : "";
                $nestedData['canteen_id'] = isset($payment->user) ? $payment->user->canteen_id : " ";
                $nestedData['amount'] = $amount;
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
            "recordsTotal" => Payment::count(),
            "recordsFiltered" => $totalFiltered,
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Payment $payment)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $studentId = $id;

        $query = Payment::where('student_id', $studentId)
                            ->with('user')
                            ->first();

        $paymentReciptData = PaymentHistory::select('id', 'payment_date', 'month_name')->where('student_id', $studentId)->get();

        $data['paymentData'] = $query;
        $data['studentId'] = $studentId;
        $data['reciptsData'] = $paymentReciptData;
        $data['validator'] =  JsValidator::make($this->validationRules);

        return view('Bill.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Payment $payment)
    {
        
        $validator = Validator::make($request->all(),$this->validationRules);
            
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }
        // now take amount and minus from current payement amount of that user.

        $payementType = $request->type;
        $paymentMonth = $request->month;
        $paymentDate = Carbon::now()->format('Y-m-d');
        $studentId = $request->student_id;


        $payment = Payment::where('student_id', $studentId)->first();

        $paymentAmount = $payment->amount ?? 0;
        $pendingAmount = $payment->pending_amount ?? 0;
        $advanceAmount = $payment->advance_amount ?? 0;

        $paymentStartDate = Carbon::parse($payment->start_date)->format('Y-m-d');
        $paymentEndDate = Carbon::parse($payment->end_date)->format('Y-m-d');

        $newAdvanceAmount = 0;
        $newPendingAmount = 0;
        $totalAmountPaidByStudent = 0;

        $totalAmount = ($paymentAmount + $pendingAmount) - $advanceAmount;

        $currentPayableAmount = $totalAmount - $request->amount;

        $totalAmountPaidByStudent = $request->amount + $pendingAmount;

        if ($currentPayableAmount < 0) {
            $newAdvanceAmount = abs($currentPayableAmount);
        } else {
            $newPendingAmount = abs($currentPayableAmount);
        }
                   
        if ($newPendingAmount > 0 || $newAdvanceAmount > 0) {

            StudentAttendance::where('student_id',$studentId)
                                ->where('date', '>=', $paymentStartDate)
                                ->where('date', '<=', $paymentEndDate)
                                ->update(['is_paid'=>1]);
          
            $payment->update([
                'amount' => 0,
                'pending_amount' => $newPendingAmount,
                'advance_amount' => $newAdvanceAmount,
                'start_date' => $paymentDate,
                'end_date'   => $paymentDate,
            ]);

            // create a new payement history 
            $payementHistory = PaymentHistory::create([
                'student_id'    => $request->student_id,
                'amount'        => $totalAmountPaidByStudent,
                'payment_date'  => $paymentDate,
                'type'          => $payementType,
                'month_name'    => $paymentMonth
            ]);

        } else {

            StudentAttendance::where('student_id',$studentId)
                                ->whereDate('date', '>=', $paymentStartDate)
                                ->whereDate('date', '<=', $paymentEndDate)
                                ->update(['is_paid'=>1]);

            $payementHistory = PaymentHistory::create([
                'student_id'    => $request->student_id,
                'amount'        => $totalAmountPaidByStudent,
                'payment_date'  => $paymentDate,
                'type'          => $payementType,
                'month_name'    => $paymentMonth
            ]);


            $payment->update([
                'amount' => 0,
                'pending_amount' => 0,
                'advance_amount' => 0,
                'start_date' => $paymentDate,
                'end_date'   => $paymentDate,
            ]);
        }


        return redirect()->route('bill.index')->with('success', 'Payment updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Payment $payment)
    {
        //
    }

    public function downloadReceipt(Request $request)
    {

        $paymentId      = $request->payment_id;
        $paymentHistory = PaymentHistory::find($paymentId);

        $studentId      = $paymentHistory->student_id;
        $paymentMode    = $paymentHistory->type;
        $paymentDate    = $paymentHistory->payment_date;
        $paymentMonth   = $paymentHistory->month_name;
        $paidAmount     = $paymentHistory->amount;

        $userDetails = User::where('id', $studentId)->first();
        
        $year = date('Y', strtotime($paymentDate));

        $filterDates = $this->getMonthStartAndEnd($year, $paymentMonth);

        $mealNames = MealPrice::select('id', 'name')->get();


        $studentAttendanceCount = StudentAttendance::select('meal_id', DB::raw('count(*) as total'))
            ->whereBetween('date', [$filterDates['start_date'], $filterDates['end_date']])
            ->where('student_id', $studentId)
            ->groupBy('meal_id')
            ->get();

        // Convert $studentAttendanceCount to a keyed collection for quick lookups by meal_id
        $attendanceByMealId = $studentAttendanceCount->keyBy('meal_id');

        // Map through mealNames and combine with attendance counts
        $combined = $mealNames->map(function ($meal) use ($attendanceByMealId) {
            return [
                'meal_id' => $meal->id,
                'meal_name' => $meal->name,
                'total' => $attendanceByMealId->has($meal->id) ? $attendanceByMealId->get($meal->id)->total : 0,
            ];
        });

        $data['user_data']        = $userDetails;
        $data['attedance_counts'] = $combined;
        $data['payment_month']    = $paymentMonth;
        $data['payment_date']     = $paymentDate;
        $data['payment_mode']     = Str::upper($paymentMode);
        $data['total_amount']     = $paidAmount;
        $data['receipt_number']   = str_pad($paymentId, 6, '0', STR_PAD_LEFT);


        $pdf = Pdf::loadView('Bill.receipt', $data);
        return $pdf->download('bill_receipt.pdf');
    }

    function getMonthStartAndEnd($year, $monthName)
    {
        $date = Carbon::createFromFormat('Y-F-d', "$year-$monthName-01");

        $startDate = $date->copy()->startOfMonth()->format('Y-m-d');
        $endDate = $date->copy()->endOfMonth()->format('Y-m-d');

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }
}

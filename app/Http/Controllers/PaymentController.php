<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Http\Controllers\Concerns\ListsRecords;
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
use Inertia\Inertia;


class PaymentController extends Controller
{
    use ListsRecords;

    /**
     * Display a listing of the resource.
     */
    protected $validationRules =[
        'amount' => ['required','numeric'],
        'type' =>  ['required', 'in:cash,upi'],
        'month' => ['required','in:January,February,March,April,May,June,July,August,September,October,November,December'],
    ];
    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name', 'canteen_id', 'balance'], 'name');
        $show = $this->text($request, 'show');
        $filters['show'] = in_array($show, ['due', 'advance', 'clear'], true) ? $show : '';

        $query = Payment::join('users', 'users.id', '=', 'payments.student_id')->select(
            'payments.id', 'payments.student_id', 'payments.amount', 'payments.pending_amount', 'payments.advance_amount',
            'users.name', 'users.middle_name', 'users.last_name', 'users.canteen_id', 'users.profile_pic'
        );
        $this->searchIn($query, ['users.name', 'users.middle_name', 'users.last_name', 'users.canteen_id'], $filters['search']);

        if ($filters['show'] !== '') {
            $sign = ['due' => '>', 'advance' => '<', 'clear' => '='][$filters['show']];
            $query->whereRaw(Payment::BALANCE . " $sign 0");
        }

        $sort = ['name' => 'users.name', 'canteen_id' => 'users.canteen_id', 'balance' => Payment::BALANCE][$filters['sort']];
        $query->orderByRaw("$sort {$filters['dir']}")->orderBy('payments.id');

        return Inertia::render('Bills/Index', [
            'bills' => $this->pageOf($query)->through(fn ($bill) => $bill->only(
                'id', 'student_id', 'name', 'middle_name', 'last_name', 'canteen_id', 'profile_pic', 'balance'
            )),
            'filters' => $filters,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $payment = Payment::where('student_id', $id)->with('user')->first();

        if (!$payment?->user) {
            return redirect()->route('bill.index')->with('error', 'There is no bill for this student yet.');
        }

        return Inertia::render('Bills/Edit', [
            'student' => $payment->user->only('id', 'name', 'middle_name', 'last_name', 'canteen_id', 'collage_name', 'profile_pic'),
            'bill' => $payment->only('id', 'amount', 'pending_amount', 'advance_amount') + ['due' => $payment->balance],
            'receipts' => PaymentHistory::where('student_id', $id)->orderByDesc('payment_date')->orderByDesc('id')
                ->get(['id', 'payment_date', 'month_name']),
            'month' => now()->format('F'),
        ]);
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


        $payment = Payment::where('student_id', $studentId)->firstOrFail();

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


        return redirect()->route('bill.index')->with('success', '₹' . $request->amount . ' saved for ' . Str::squish($payment->user?->full_name ?? '') . '.');
    }

    public function downloadReceipt(string $paymentId)
    {
        $paymentHistory = PaymentHistory::findOrFail($paymentId);

        $studentId      = $paymentHistory->student_id;
        $paymentMode    = $paymentHistory->type;
        $paymentDate    = $paymentHistory->payment_date;
        $paymentMonth   = $paymentHistory->month_name;
        $paidAmount     = $paymentHistory->amount;

        $userDetails = User::withTrashed()->findOrFail($studentId);
        
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

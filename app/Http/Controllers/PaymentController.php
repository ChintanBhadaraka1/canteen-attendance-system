<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Models\Payment;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\PaymentHistory;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;


class PaymentController extends Controller
{
    use ListsRecords;

    /**
     * Display a listing of the resource.
     */
    protected $validationRules =[
        'amount' => ['bail', 'required', 'integer', 'between:1,1000000'],
        'due' => ['required', 'integer'],
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
            'users.name', 'users.middle_name', 'users.last_name', 'users.canteen_id', 'users.profile_pic', 'users.left_on'
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
            ) + ['left' => $bill->left_on !== null]),
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

        $guests = (int) GuestMeal::where('student_id', $id)->where('is_paid', '0')->notCancelled()->sum('amount');

        return Inertia::render('Bills/Edit', [
            'student' => $payment->user->only('id', 'name', 'middle_name', 'last_name', 'canteen_id', 'collage_name', 'profile_pic'),
            'bill' => $payment->only('id', 'amount', 'pending_amount', 'advance_amount') + ['due' => $payment->balance, 'own' => $payment->amount - $guests, 'guests' => $guests],
            'receipts' => PaymentHistory::where('student_id', $id)->orderByDesc('payment_date')->orderByDesc('id')
                ->get()->map(fn ($row) => $row->only('id', 'payment_date', 'month_name', 'cancel_reason') + [
                    'amount' => $row->before_amount === null ? null : $row->amount,
                    'cancelled_on' => $row->cancelled_at?->toDateString(),
                    'cancellable' => $row->isCancellable(),
                ]),
            'month' => now()->format('F'),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BillingService $billing, string $bill)
    {
        $studentId = Payment::findOrFail($bill)->student_id;
        $input = $request->validate($this->validationRules);

        try {
            $payment = $billing->takePayment((int) $bill, (int) $input['amount'], $input['type'], $input['month'], (int) $input['due']);
        } catch (BillingException $e) {
            return redirect()->route('bill.edit', $studentId)->with('error', $e->getMessage());
        }

        return redirect()->route('bill.index')
            ->with('success', "₹{$payment->amount} saved for " . User::withTrashed()->find($studentId)->full_name . '.');
    }

    public function cancel(Request $request, BillingService $billing, string $history)
    {
        $payment = PaymentHistory::findOrFail($history);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        try {
            $billing->cancelPayment($payment->id, $input['reason']);
        } catch (BillingException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        $due = Payment::where('student_id', $payment->student_id)->sole()->balance;
        $now = $due > 0 ? "₹$due is now due." : ($due < 0 ? '₹' . -$due . ' is kept as advance.' : 'Nothing is due.');

        return redirect()->route('bill.edit', $payment->student_id)
            ->with('success', "Payment of ₹{$payment->amount} cancelled. $now");
    }

    public function downloadReceipt(string $paymentId)
    {
        $paymentHistory = PaymentHistory::findOrFail($paymentId);

        $studentId      = $paymentHistory->student_id;
        $paymentMode    = $paymentHistory->type;
        $paymentDate    = $paymentHistory->payment_date;
        $paymentMonth   = $paymentHistory->month_name;
        $paidAmount     = $paymentHistory->before_amount === null ? null : $paymentHistory->amount;

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

        $data['payment']          = $paymentHistory;
        $data['user_data']        = $userDetails;
        $data['attedance_counts'] = $combined;
        $data['guest_plates']     = (int) GuestMeal::where('student_id', $studentId)->notCancelled()
            ->whereBetween('date', [$filterDates['start_date'], $filterDates['end_date']])->sum('plates');
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

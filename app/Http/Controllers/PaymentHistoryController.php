<?php

namespace App\Http\Controllers;

use App\Models\PaymentHistory;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentHistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //

        // history
        //  $startUserId = 501;
        // $endUserId = 678;
        // $monthsCount = 6;


        // for ($userId = $startUserId; $userId <= $endUserId; $userId++) {
        //     for ($monthOffset = 0; $monthOffset < $monthsCount; $monthOffset++) {
        //         $paymentDate = Carbon::now()->subMonths($monthOffset);
        //         $monthName = $paymentDate->format('F');

        //         // Create payment record for this user and month
        //         \App\Models\PaymentHistory::create([
        //             'student_id' => $userId,
        //             'amount' => rand(100, 1000),
        //             'pending_amount' => rand(0, 500),
        //             'advance_amount' => rand(0, 500),
        //             'payment_date' => $paymentDate->format('Y-m-d'),
        //             'month_name' => $monthName,
        //             'type' => ['cash', 'upi'][array_rand(['cash', 'upi'])],
        //         ]);
        //     }
        // }

        $startStudentId = 2;
        $endStudentId = 677;

        for ($studentId = $startStudentId; $studentId <= $endStudentId; $studentId++) {
            \App\Models\Payment::create([
                'student_id' => $studentId,
                'amount' => rand(500, 3000),
                'pending_amount' => rand(0, 1000),
                'advance_amount' => rand(0, 1000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        dd("Add pay,emt");
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
    public function show(PaymentHistory $paymentHistory)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PaymentHistory $paymentHistory)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PaymentHistory $paymentHistory)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PaymentHistory $paymentHistory)
    {
        //
    }
}

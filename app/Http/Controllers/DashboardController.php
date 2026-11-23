<?php

namespace App\Http\Controllers;

use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $today = today();

        $todayTotals = StudentAttendance::where('date', $today->toDateString())
            ->groupBy('meal_id')
            ->pluck(DB::raw('count(*)'), 'meal_id');

        $weekTotals = StudentAttendance::whereBetween('date', [$today->copy()->subDays(6)->toDateString(), $today->toDateString()])
            ->groupBy('date')
            ->pluck(DB::raw('count(*)'), 'date');

        // whereHas leaves out bills of deleted students.
        $bills = Payment::whereRaw(Payment::BALANCE . ' > 0')->whereHas('user', fn ($q) => $q->withoutTrashed());

        return Inertia::render('Dashboard', [
            'meals' => MealPrice::get(['id', 'name'])->map(fn ($meal) => [
                'id' => $meal->id,
                'name' => $meal->name,
                'total' => (int) ($todayTotals[$meal->id] ?? 0),
            ]),
            'students' => User::where('role', 'user')->count(),
            'due' => [
                'total' => (int) (clone $bills)->sum(DB::raw(Payment::BALANCE)),
                'students' => (clone $bills)->count(),
            ],
            'week' => collect(range(6, 0))->map(function ($daysAgo) use ($today, $weekTotals) {
                $day = $today->copy()->subDays($daysAgo);

                return [
                    'date' => $day->toDateString(),
                    'label' => $daysAgo === 0 ? 'Today' : $day->format('D'),
                    'total' => (int) ($weekTotals[$day->toDateString()] ?? 0),
                ];
            }),
            'highest' => (clone $bills)->with('user')->orderByRaw(Payment::BALANCE . ' desc')->limit(5)->get()
                ->map(fn ($bill) => [
                    'id' => $bill->user->id,
                    'name' => Str::squish($bill->user->full_name),
                    'canteen_id' => $bill->user->canteen_id,
                    'due' => $bill->balance,
                ]),
        ]);
    }
}

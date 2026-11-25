<?php

namespace App\Http\Controllers;

use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StockItem;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\MenuCalendar;
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

        $guests = GuestMeal::notCancelled();
        $todayGuests = (clone $guests)->where('date', $today->toDateString())->groupBy('meal_id')->pluck(DB::raw('sum(plates)'), 'meal_id');
        $weekGuests = (clone $guests)->whereBetween('date', [$today->copy()->subDays(6)->toDateString(), $today->toDateString()])
            ->groupBy('date')->pluck(DB::raw('sum(plates)'), 'date');
        $walkIn = (clone $guests)->where('type', 'walk_in')->where('date', $today->toDateString())->groupBy('method')->pluck(DB::raw('sum(amount)'), 'method');

        // whereHas leaves out bills of deleted students.
        $bills = Payment::whereRaw(Payment::BALANCE . ' > 0')->whereHas('user', fn ($q) => $q->withoutTrashed());

        return Inertia::render('Dashboard', [
            'meals' => MealPrice::active()->get(['id', 'name'])->map(fn ($meal) => [
                'id' => $meal->id,
                'name' => $meal->name,
                'total' => (int) ($todayTotals[$meal->id] ?? 0),
                'guests' => (int) ($todayGuests[$meal->id] ?? 0),
            ]),
            'menu' => MenuCalendar::between($today->toDateString(), $today->toDateString())[$today->toDateString()],
            'walkIn' => ['cash' => (int) ($walkIn['cash'] ?? 0), 'upi' => (int) ($walkIn['upi'] ?? 0)],
            'low' => [
                'total' => StockItem::low()->count(),
                'items' => StockItem::low()->orderBy('name')->limit(5)->get()->map->only('id', 'name', 'unit', 'on_hand'),
            ],
            'students' => User::active()->count(),
            'due' => [
                'total' => (int) (clone $bills)->sum(DB::raw(Payment::BALANCE)),
                'students' => (clone $bills)->count(),
            ],
            'week' => collect(range(6, 0))->map(function ($daysAgo) use ($today, $weekTotals, $weekGuests) {
                $day = $today->copy()->subDays($daysAgo);

                return [
                    'date' => $day->toDateString(),
                    'label' => $daysAgo === 0 ? 'Today' : $day->format('D'),
                    'total' => (int) ($weekTotals[$day->toDateString()] ?? 0),
                    'guests' => (int) ($weekGuests[$day->toDateString()] ?? 0),
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

<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\AttendanceBatch;
use App\Models\MealPrice;
use App\Models\StudentAttendance;
use App\Models\StudentLeave;
use App\Models\User;
use App\Services\BillingService;
use App\Services\MenuCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BulkAttendanceController extends Controller
{
    use ListsRecords;

    public function index(Request $request)
    {
        ['date' => $date, 'meal' => $mealId] = $this->chosen($request);

        $entries = StudentAttendance::where('meal_id', $mealId);
        $marked = (clone $entries)->where('date', $date)->pluck('student_id')->flip();
        $lastDate = (clone $entries)->where('date', '<', $date)->max('date');
        $lastPaid = BillingService::lastPaidDates();
        $away = StudentLeave::during($date)->pluck('to_date', 'student_id');

        return Inertia::render('Attendance/Bulk', [
            'meals' => MealPrice::offeredOn($date),
            'menu' => MenuCalendar::between($date, $date)[$date],
            'meal' => $mealId,
            'date' => $date,
            'students' => User::active()->orderBy('name')->orderBy('id')->get()->map(fn ($user) => $user->only(
                'id', 'name', 'middle_name', 'last_name', 'canteen_id', 'collage_name', 'profile_pic'
            ) + [
                'marked' => $marked->has($user->id),
                'paid_block' => isset($lastPaid[$user->id]) && $date < $lastPaid[$user->id],
                'away' => $away[$user->id] ?? null,
            ]),
            'last' => $lastDate === null ? [] : (clone $entries)->where('date', $lastDate)->orderBy('id')->pluck('student_id'),
            'batches' => $this->recent(),
        ]);
    }

    public function store(Request $request, BillingService $billing)
    {
        $input = $request->validate([
            'students' => ['bail', 'required', 'array', 'max:500', function ($name, $ids, $fail) {
                if (array_filter($ids, fn ($id) => ! is_int($id) && ! (is_string($id) && ctype_digit($id)))
                    || count(array_unique($ids)) !== count($ids)
                    || User::withTrashed()->where('role', 'user')->whereIn('id', $ids)->count() !== count($ids)) {
                    $fail('The list of students is not valid. Reload the page and try again.');
                }
            }],
            'meal_id' => ['bail', 'required', 'integer', 'exists:meal_prices,id'],
            'date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'price' => ['required', 'integer'],
        ], ['students.max' => 'Please save at most 500 students at a time.']);
        $back = redirect()->route('attendance.bulk', ['date' => $input['date'], 'meal' => (int) $input['meal_id']]);

        try {
            $result = $billing->markMany(array_map(intval(...), $input['students']), (int) $input['meal_id'], $input['date'], (int) $input['price']);
        } catch (BillingException $e) {
            return $back->with('error', $e->getMessage());
        }

        $back->with('bulk', ['batch' => $result['batch']?->id, 'skipped' => $result['skipped']]);

        return $result['batch'] === null
            ? $back->with('error', 'Nobody was marked.')
            : $back->with('success', "{$result['batch']->meal_name} marked for {$result['marked']} " . Str::plural('student', $result['marked'])
                . ' (₹' . number_format($result['total']) . ').');
    }

    public function undo(Request $request, BillingService $billing, string $batch)
    {
        $id = AttendanceBatch::findOrFail($batch)->id;
        $back = redirect()->route('attendance.bulk', array_filter($this->chosen($request)));

        try {
            $undone = $billing->undoBatch($id);
        } catch (BillingException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('success', "{$undone->meal_name} on " . Carbon::parse($undone->date)->format('j F Y')
            . " undone for {$undone->undone_count} " . Str::plural('student', $undone->undone_count) . '.');
    }

    // Anything invalid falls back to today and no meal.
    private function chosen(Request $request): array
    {
        $valid = Validator::make($request->only('date'), ['date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today']])->passes();
        $meal = $request->input('meal');

        return [
            'date' => $valid ? $request->input('date') : today()->toDateString(),
            'meal' => is_scalar($meal) ? MealPrice::active()->whereKey((int) $meal)->value('id') : null,
        ];
    }

    private function recent()
    {
        $batches = AttendanceBatch::withCount(['entries', 'entries as paid_count' => fn ($q) => $q->where('is_paid', '1')])
            ->latest('id')->limit(10)->get();

        return $batches->map(fn ($batch) => $batch->only('id', 'meal_name', 'date', 'user_name', 'count') + [
            'when' => $batch->created_at->format('j M Y, g:i a'),
            'blocked' => match (true) {
                $batch->undone_at !== null => 'Already undone',
                $batch->paid_count > 0 => 'Some students have already paid',
                $batch->entries_count === 0 => 'All entries were deleted',
                default => null,
            },
        ]);
    }
}

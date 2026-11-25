<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\MealPrice;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\BillingService;
use App\Services\MenuCalendar;
use Illuminate\Http\Request;
use Inertia\Inertia;

class QuickAttendanceController extends Controller
{
    use ListsRecords;

    public function index(Request $request)
    {
        $today = today()->toDateString();
        $mealId = MealPrice::active()->whereKey((int) $this->text($request, 'meal'))->value('id');

        return Inertia::render('Attendance/Quick', [
            'meals' => MealPrice::offeredOn($today),
            'menu' => MenuCalendar::between($today, $today)[$today],
            'meal' => $mealId,
            'recent' => StudentAttendance::with('user')->where('meal_id', $mealId)->where('date', $today)
                ->latest('id')->limit(10)->get()
                ->map(fn ($entry) => [
                    'id' => $entry->id,
                    'name' => $entry->user->full_name ?? 'Removed student',
                    'photo' => $entry->user?->profile_pic,
                    'canteen_id' => $entry->user?->canteen_id,
                    'is_paid' => $entry->is_paid === '1',
                ]),
        ]);
    }

    public function store(Request $request, BillingService $billing)
    {
        $input = $request->validate([
            'canteen_id' => ['bail', 'required', 'string', 'max:10'],
            'meal_id' => ['bail', 'required', 'integer', 'exists:meal_prices,id'],
        ], ['canteen_id.max' => 'A canteen ID has at most 10 letters or numbers.']);
        $back = redirect()->route('attendance.quick', ['meal' => (int) $input['meal_id']]);
        $wanted = mb_strtolower($input['canteen_id']);

        // Compared again here: MariaDB also treats letters with accents as equal.
        $student = User::where('role', 'user')->whereRaw('lower(canteen_id) = ?', [$wanted])->get()
            ->first(fn ($user) => mb_strtolower($user->canteen_id) === $wanted);
        if ($student === null) {
            return $back->withErrors(['canteen_id' => "No student has canteen ID {$input['canteen_id']}."]);
        }

        try {
            $entry = $billing->markAttendance($student->id, (int) $input['meal_id'], today()->toDateString());
        } catch (BillingException $e) {
            $message = str_starts_with($e->getMessage(), $student->full_name) ? $e->getMessage() : "{$student->full_name}: {$e->getMessage()}";

            // A double scan and a friend look the same, so the screen offers a guest plate and never adds one.
            $already = StudentAttendance::where('student_id', $student->id)->where('meal_id', (int) $input['meal_id'])->where('date', today()->toDateString())->exists();

            return $back->withErrors(['canteen_id' => $message])->with($already ? ['already' => $student->id] : []);
        }

        $away = $student->isAwayOn($entry->date);

        return $back->with('marked', [
            'id' => $entry->id,
            'student_id' => $student->id,
            'name' => $student->full_name,
            'photo' => $student->profile_pic,
            'canteen_id' => $student->canteen_id,
            'meal' => $entry->meal->name,
            'away' => $away,
            'message' => "{$student->full_name} marked for {$entry->meal->name}." . ($away ? ' They are noted as away today.' : ''),
        ]);
    }
}

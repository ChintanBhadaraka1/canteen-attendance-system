<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingException;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\Menus;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\User;
use App\Services\BillingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GuestMealController extends Controller
{
    use ListsRecords;

    private const DETAILS = ['id', 'name', 'middle_name', 'last_name', 'canteen_id', 'profile_pic'];

    public function index(Request $request)
    {
        $valid = Validator::make($request->only('date'), ['date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today']])->passes();
        $date = $valid ? $request->input('date') : today()->toDateString();
        $student = User::active()->find((int) $this->text($request, 'student'));
        $type = $this->text($request, 'type');

        return Inertia::render('Attendance/Guest', [
            'type' => in_array($type, ['card', 'walk_in'], true) ? $type : ($student ? 'card' : null),
            'date' => $date,
            'meal' => MealPrice::active()->whereKey((int) $this->text($request, 'meal'))->value('id'),
            'meals' => MealPrice::offeredOn($date),
            'extras' => Menus::extras()->get(['id', 'name', 'price']),
            'students' => fn () => User::active()->orderBy('name')->orderBy('id')->get()->map->only(self::DETAILS),
            'student' => $student ? $student->only(self::DETAILS) + [
                'due' => Payment::where('student_id', $student->id)->first()->balance ?? 0,
                'own' => StudentAttendance::where('student_id', $student->id)->where('date', $date)->pluck('meal_id'),
                'away' => $student->isAwayOn($date),
            ] : null,
            'recent' => GuestMeal::with('student', 'extras')->where('date', $date)->latest('id')->limit(10)->get()
                ->map(fn ($guest) => $guest->row() + ['student' => $guest->student?->full_name]),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function card(Request $request, BillingService $billing)
    {
        $input = $request->validate([
            'student_id' => ['bail', 'required', 'integer', Rule::exists('users', 'id')->where('role', 'user')->whereNull('deleted_at')],
        ] + $this->rules());

        return $this->saved($input, 'card', fn () => $billing->addCardGuest((int) $input['student_id'], $this->data($input)));
    }

    public function walkIn(Request $request, BillingService $billing)
    {
        $input = $request->validate(['method' => ['bail', 'required', 'string', 'in:cash,upi']]
            + array_merge_recursive($this->rules(), ['date' => ['after_or_equal:' . today()->subDays(366)->toDateString()]]),
            ['method.required' => 'Choose cash or UPI.', 'date.after_or_equal' => 'Choose a date in the last year.']);

        return $this->saved($input, 'walk_in', fn () => $billing->addWalkIn($this->data($input) + ['method' => $input['method']]));
    }

    public function cancel(Request $request, BillingService $billing, string $guest)
    {
        $row = GuestMeal::findOrFail($guest);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        // Built from checked values, never from a URL sent by the browser.
        $back = $request->input('return_to') === 'history' && $row->isCard()
            ? redirect()->route('student-attendance.show', ['id' => $row->student_id] + $this->dates($request))
            : redirect()->route('guest.index', array_filter(['type' => $row->type, 'student' => (int) $this->text($request, 'student'), 'date' => $row->date]));

        try {
            $row = $billing->cancelGuest($row->id, $input['reason']);
        } catch (BillingException $e) {
            return $back->withErrors(['reason' => $e->getMessage()]);
        }

        return $back->with('success', $row->label() . ' cancelled. ' . ($row->isCard()
            ? "₹{$row->amount} taken off the bill of {$row->student->full_name}."
            : "₹{$row->amount} {$row->methodName()} to give back."));
    }

    private function rules(): array
    {
        return [
            'meal_id' => ['bail', 'required', 'integer', 'exists:meal_prices,id'],
            'date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'plates' => ['bail', 'required', 'integer', 'between:1,20'],
            'expected_total' => ['bail', 'required', 'integer'],
            'extras' => ['nullable', 'array'],
            'extras.*' => ['nullable', 'integer', 'between:0,99'],
            'guest_name' => ['nullable', 'string', 'max:60'],
            'token' => ['bail', 'required', 'string', 'uuid'],
        ];
    }

    private function data(array $input): array
    {
        $extras = [];
        foreach ($input['extras'] ?? [] as $id => $quantity) {
            $extras[(int) $id] = (int) $quantity;
        }

        return [
            'meal_id' => (int) $input['meal_id'], 'date' => $input['date'], 'plates' => (int) $input['plates'],
            'expected_total' => (int) $input['expected_total'], 'extras' => $extras, 'guest_name' => $input['guest_name'] ?? null, 'token' => $input['token'],
        ];
    }

    private function saved(array $input, string $type, Closure $save)
    {
        $back = ['type' => $type, 'meal' => (int) $input['meal_id'], 'date' => $input['date']];

        try {
            $guest = $save();
        } catch (BillingException $e) {
            return redirect()->route('guest.index', $back + array_filter(['student' => (int) ($input['student_id'] ?? 0)]))->withErrors(['guest' => $e->getMessage()]);
        }

        return redirect()->route('guest.index', $back)->with('success', $guest->isCard()
            ? "{$guest->label()} added for {$guest->student->full_name} (₹{$guest->amount})."
            : "{$guest->label()} saved. ₹{$guest->amount} received by {$guest->methodName()}.");
    }
}

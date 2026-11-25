<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\MealPrice;
use App\Models\MenuPlan;
use App\Models\Menus;
use App\Services\MenuCalendar;
use App\Services\MenuPlanner;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class MenuPlanController extends Controller
{
    use ListsRecords;

    public function index(Request $request)
    {
        $monday = ($this->date($request, 'week') ?? today())->startOfWeek(Carbon::MONDAY);
        $sunday = $monday->copy()->addDays(6)->toDateString();

        return Inertia::render('MenuPlan/Index', [
            'week' => $monday->toDateString(),
            'previous' => $monday->copy()->subWeek()->toDateString(),
            'next' => $monday->copy()->addWeek()->toDateString(),
            'meals' => MealPrice::active()->get(['id', 'name']),
            'menus' => MenuCalendar::between($monday->toDateString(), $sunday),
            'prices' => MealPrice::pricesBetween($monday->toDateString(), $sunday),
        ]);
    }

    public function edit(Request $request)
    {
        $meal = MealPrice::active()->find((int) $this->text($request, 'meal'));
        $date = $this->date($request, 'date')?->toDateString();
        if ($meal === null || $date === null || $date < today()->toDateString()) {
            return redirect()->route('menu-plan.index')->with('error', 'This menu cannot be changed. Choose a meal from today onwards.');
        }

        $cell = MenuCalendar::between($date, $date)[$date][$meal->id];
        $plan = $cell === null ? null : MenuPlan::find($cell['plan_id']);
        $other = $plan === null ? null : MenuCalendar::without($plan, $date);

        return Inertia::render('MenuPlan/Form', [
            'meal' => $meal->only('id', 'name'),
            'date' => $date,
            'plan' => $cell === null ? null : $cell + [
                'until' => $plan->ends_on,
                'instead' => $other === null ? null : ($other['closed'] ? 'No meal' : implode(', ', array_column($other['items'], 'name'))),
            ],
            'items' => Menus::offered()->with('foodType', 'category')->orderBy('name')->orderBy('id')->get()->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'food_type' => $item->foodType?->name,
                'category' => $item->category?->name,
                'place' => $item->category?->position,
            ]),
        ]);
    }

    public function store(Request $request, MenuPlanner $planner)
    {
        $closed = $request->boolean('closed');
        $input = $request->validate([
            'meal_id' => ['bail', 'required', 'integer', 'exists:meal_prices,id'],
            'from' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'repeat' => ['required', 'in:once,weekly'],
            'weekdays' => ['bail', 'exclude_unless:repeat,weekly', 'required', 'array', 'max:7'],
            'weekdays.*' => ['bail', 'integer', 'between:1,7', 'distinct'],
            'until' => ['bail', 'exclude_unless:repeat,weekly', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from', function ($name, $until, $fail) use ($request) {
                $from = $this->date($request, 'from');
                if ($from && $until > $from->addDays(366)->toDateString()) {
                    $fail('Choose a date within 366 days.');
                }
            }],
            'items' => ['bail', $closed ? 'nullable' : 'required', 'array', 'max:30', function ($name, $ids, $fail) {
                if (array_filter($ids, fn ($id) => ! is_int($id) && ! (is_string($id) && ctype_digit($id)))
                    || count(array_unique($ids)) !== count($ids)
                    || Menus::offered()->whereKey($ids)->count() !== count($ids)) {
                    $fail('A menu item is no longer available. Please choose again.');
                }
            }],
            'closed' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:100'],
        ], [
            'items.required' => 'Choose at least one item, or "No meal".', 'items.max' => 'Choose at most 30 items.',
            'weekdays.required' => 'Choose at least one day.', 'from.after_or_equal' => 'Choose today or a later date.',
            'until.after_or_equal' => 'Choose a date that is not before the first date.',
        ]);
        $weekdays = array_map(intval(...), $input['weekdays'] ?? []);
        sort($weekdays);
        $until = $input['until'] ?? null;
        if ($until !== null && ! $this->falls($input['from'], $until, $weekdays)) {
            return back()->withErrors(['weekdays' => 'None of these days is between the two dates.']);
        }

        try {
            $planner->save((int) $input['meal_id'], $input['from'], $input['repeat'], $weekdays, $until,
                array_map(intval(...), array_values($input['items'] ?? [])), $closed, $input['note'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('menu-plan.index', ['week' => Carbon::parse($input['from'])->startOfWeek(Carbon::MONDAY)->toDateString()])
            ->with('success', 'Menu saved.');
    }

    public function stop(Request $request, MenuPlanner $planner, string $plan)
    {
        $id = MenuPlan::findOrFail($plan)->id;
        $back = redirect()->route('menu-plan.index', array_filter(['week' => $this->date($request, 'date')?->startOfWeek(Carbon::MONDAY)->toDateString()]));

        try {
            $planner->stop($id);
        } catch (DomainException $e) {
            return $back->with('error', $e->getMessage());
        }

        return $back->with('success', 'Menu stopped.');
    }

    public function copy(Request $request, MenuPlanner $planner)
    {
        $request->validate(['week' => ['required', 'date_format:Y-m-d']]);
        $to = $this->date($request, 'week')->startOfWeek(Carbon::MONDAY);
        $from = $to->copy()->subWeek();

        $copied = $planner->copyWeek($from->toDateString(), $to->toDateString());
        $shown = collect(MenuCalendar::between($from->toDateString(), $from->copy()->addDays(6)->toDateString()))->sum(fn ($cells) => count(array_filter($cells)));

        return redirect()->route('menu-plan.index', ['week' => $to->toDateString()])
            ->with('success', "$copied copied, " . max(0, $shown - $copied) . ' skipped.');
    }

    private function date(Request $request, string $key): ?Carbon
    {
        $valid = Validator::make($request->only($key), [$key => ['bail', 'required', 'string', 'date_format:Y-m-d']])->passes();

        return $valid ? Carbon::parse($request->input($key)) : null;
    }

    private function falls(string $from, string $until, array $weekdays): bool
    {
        foreach (CarbonPeriod::create($from, $until) as $day) {
            if (in_array($day->dayOfWeekIso, $weekdays, true)) {
                return true;
            }
        }

        return false;
    }
}

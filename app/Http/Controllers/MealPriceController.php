<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Http\Controllers\Concerns\MovesRows;
use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\SpecialPrice;
use App\Models\StudentAttendance;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MealPriceController extends Controller
{
    use MovesRows;

    protected $validationRules = [
        'name' => ['required', 'string', 'min:2', 'max:30'],
        'price' => ['bail', 'required', 'integer', 'between:0,100000'],
        'active' => ['sometimes', 'required', 'boolean'],
        'serve_from' => ['bail', 'nullable', 'required_with:serve_to', 'date_format:H:i'],
        'serve_to' => ['bail', 'nullable', 'required_with:serve_from', 'date_format:H:i', 'after:serve_from'],
    ];

    protected $messages = [
        'serve_from.required_with' => 'Enter both times, or leave both empty.',
        'serve_to.required_with' => 'Enter both times, or leave both empty.',
        'serve_to.after' => 'Enter a time after the first time.',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $specials = SpecialPrice::orderBy('from_date')->get()->groupBy('meal_id');

        return Inertia::render('MealPrices/Index', [
            'meals' => MealPrice::orderBy('position')->orderBy('id')->get()->map(fn ($meal) => $this->meal($meal) + [
                'specials' => $specials->get($meal->id, collect())
                    ->map(fn ($row) => $row->only('id', 'from_date', 'to_date', 'price', 'reason') + ['marked' => $row->marked()])->values(),
            ]),
        ]);
    }

    private function meal(MealPrice $meal): array
    {
        return $meal->only('id', 'name', 'price', 'active', 'serve_from', 'serve_to');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('MealPrices/Form', ['meal' => null]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), $this->validationRules, $this->messages);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        $input = $validator->validated();

        $input['slug'] = Str::slug($input['name']);

        $meal = DB::transaction(function () use ($input) {
            $meal = MealPrice::create($input + ['position' => MealPrice::max('position') + 1]);
            ActivityLog::record('meal.added', null, "{$meal->name} added (₹{$meal->price})");

            return $meal;
        });

        return redirect()->route('meal-price.index')->with('success', $meal->name . ' added.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        return Inertia::render('MealPrices/Form', ['meal' => $this->meal(MealPrice::findOrFail($id))]);
    }

    /**
     * Update the specified resource in storage.
     */
     public function update(Request $request, $id)
    {
        $meal = MealPrice::findOrFail($id);

        // Validate the request with your existing rules (you can reuse the same $this->validationRules or define update rules if needed)
        $validator = Validator::make($request->all(), $this->validationRules, $this->messages);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $input = $validator->validated();

        $input['slug'] = Str::slug($input['name']);

        $meal = DB::transaction(function () use ($id, $input) {
            // First query on purpose: attendance takes a shared lock. All meals, so two cannot be turned off at once.
            $meals = MealPrice::orderBy('id')->lockForUpdate()->get();
            $meal = $meals->find($id) ?? abort(404);
            $meal->fill($input);
            if (! $meal->active && $meals->where('active', true)->isEmpty()) {
                throw ValidationException::withMessages(['active' => "{$meal->name} is the only meal that is on, so it cannot be turned off."]);
            }
            $summary = ActivityLog::describe($meal, [
                'name' => 'name', 'price' => 'price', 'active' => $meal->active ? 'turned on' : 'turned off', 'serve_from' => 'serving time', 'serve_to' => 'serving time',
            ]);
            $meal->save();
            if ($summary !== '') {
                ActivityLog::record('meal.changed', null, $summary);
            }

            return $meal;
        }, 3);

        return redirect()->route('meal-price.index')->with('success', 'Changes to ' . $meal->name . ' saved.');
    }

    public function move(Request $request, string $meal)
    {
        $input = $request->validate(['direction' => ['required', 'in:up,down']]);
        $id = MealPrice::findOrFail($meal)->id;

        DB::transaction(function () use ($id, $input) {
            // Locked in ID order, like every other lock on meals.
            $rows = MealPrice::orderBy('id')->lockForUpdate()->get()->sortBy([['position', 'asc'], ['id', 'asc']]);
            if ($moved = $this->moveRow($rows, $id, $input['direction'])) {
                ActivityLog::record('meal.changed', null, "{$moved->name} moved {$input['direction']}");
            }
        }, 3);

        return redirect()->route('meal-price.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        [$meal, $used] = DB::transaction(function () use ($id) {
            // First query on purpose: attendance takes a shared lock on the meal.
            $meal = MealPrice::whereKey($id)->lockForUpdate()->firstOrFail();
            $used = match (true) {
                StudentAttendance::where('meal_id', $meal->id)->exists() => 'attendance uses',
                GuestMeal::where('meal_id', $meal->id)->exists() => 'guest plates use',
                default => null,
            };
            if ($used === null) {
                $meal->delete();
                ActivityLog::record('meal.deleted', null, $meal->name . ' deleted');
            }

            return [$meal, $used];
        }, 3);

        return $used
            ? redirect()->route('meal-price.index')->with('error', $meal->name . ' cannot be deleted because ' . $used . ' it.')
            : redirect()->route('meal-price.index')->with('success', $meal->name . ' deleted.');
    }
}

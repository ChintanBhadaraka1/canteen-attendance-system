<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MealPrice;
use App\Models\SpecialPrice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SpecialPriceController extends Controller
{
    public function store(Request $request, string $meal)
    {
        return $this->save($request, (int) MealPrice::findOrFail($meal)->id, null);
    }

    public function update(Request $request, string $special)
    {
        $row = SpecialPrice::findOrFail($special);

        return $this->save($request, (int) $row->meal_id, $row->id);
    }

    public function destroy(string $special)
    {
        $mealId = SpecialPrice::findOrFail($special)->meal_id;

        DB::transaction(function () use ($mealId, $special) {
            $meal = $this->lock($mealId);
            $row = SpecialPrice::findOrFail($special);
            $row->delete();
            ActivityLog::record('special_price.removed', null, $this->describe($meal, $row) . ' removed');
        }, 3);

        return redirect()->route('meal-price.index')->with('success', 'Special price removed. Meals already marked keep their price.');
    }

    private function save(Request $request, int $mealId, ?int $id)
    {
        $input = $this->checked($request);

        $row = DB::transaction(function () use ($input, $mealId, $id) {
            $meal = $this->lock($mealId);
            $row = $id === null ? new SpecialPrice(['meal_id' => $meal->id]) : SpecialPrice::findOrFail($id);
            $row->fill($input);

            $other = SpecialPrice::where('meal_id', $meal->id)->between($row->from_date, $row->to_date)
                ->when($id, fn ($q) => $q->whereKeyNot($id))->first();
            if ($other) {
                throw ValidationException::withMessages(['from_date' => "{$meal->name} already has a special price from "
                    . "{$this->day($other->from_date)} to {$this->day($other->to_date)} ({$other->reason})."]);
            }

            $row->save();
            ActivityLog::record($id === null ? 'special_price.added' : 'special_price.changed', null,
                $this->describe($meal, $row) . ($id === null ? ' added' : ' saved'));

            return $row;
        }, 3);

        $marked = $row->marked();

        return redirect()->route('meal-price.index')->with('success', 'Special price saved.' . match (true) {
            $marked === 0 => '',
            $marked === 1 => ' 1 meal already marked on these dates keeps its price.',
            default => " $marked meals already marked on these dates keep their price.",
        });
    }

    private function checked(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'price' => ['bail', 'required', 'integer', 'between:0,100000'],
            'from_date' => ['bail', 'required', 'date_format:Y-m-d'],
            'to_date' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'reason' => ['bail', 'required', 'string', 'min:2', 'max:60'],
        ], ['to_date.after_or_equal' => 'Choose a date that is not before the first date.'], ['from_date' => 'first date', 'to_date' => 'last date']);
        $validator->after(function ($validator) {
            $data = $validator->getData();
            if ($validator->errors()->isEmpty() && $data['to_date'] > Carbon::parse($data['from_date'])->addDays(366)->toDateString()) {
                $validator->errors()->add('to_date', 'Choose a date within 366 days.');
            }
        });

        return $validator->validate();
    }

    // First query of every change: marking a meal holds a shared lock on it.
    private function lock(int $mealId): MealPrice
    {
        return MealPrice::whereKey($mealId)->lockForUpdate()->firstOrFail();
    }

    private function describe(MealPrice $meal, SpecialPrice $row): string
    {
        return "{$meal->name} special price ₹{$row->price}, {$this->day($row->from_date)} to {$this->day($row->to_date)} ({$row->reason}),";
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('j M Y');
    }
}

<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MealPrice;
use App\Models\MenuPlan;
use App\Models\Menus;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

// Every write to menu plans. The meal is locked by the first query, so plans of one meal are saved one after another.
class MenuPlanner
{
    public function save(int $mealId, string $from, string $repeat, array $weekdays, ?string $until, array $menuIds, bool $closed, ?string $note): void
    {
        DB::transaction(function () use ($mealId, $from, $repeat, $weekdays, $until, $menuIds, $closed, $note) {
            $meal = $this->lock($mealId);
            if (! $meal->active) {
                throw new DomainException("{$meal->name} is turned off in Meal prices.");
            }
            $menuIds = $closed ? [] : $menuIds;
            // Locked, so an item cannot be deleted meanwhile.
            if (Menus::offered()->whereKey($menuIds)->orderBy('id')->sharedLock()->get()->count() !== count($menuIds)) {
                throw new DomainException('A menu item is no longer available. Please choose again.');
            }

            if ($repeat === 'once') {
                MenuPlan::where('meal_id', $meal->id)->whereNull('weekday')->where('starts_on', $from)->get()->each->delete();
                $this->create($meal, null, $from, null, $menuIds, $closed, $note);
            } else {
                foreach ($weekdays as $weekday) {
                    $this->create($meal, $weekday, $from, $until, $menuIds, $closed, $note);
                }
            }

            $days = $repeat === 'once' ? 'on ' . $this->day($from)
                : 'every ' . implode(', ', array_map($this->weekday(...), $weekdays)) . ' from ' . $this->day($from) . ($until ? ' until ' . $this->day($until) : '');
            ActivityLog::record('menu_plan.saved', null, "{$meal->name} menu $days " . ($closed ? 'set to no meal' : 'saved'));
        }, 3);
    }

    public function stop(int $planId): void
    {
        $mealId = MenuPlan::findOrFail($planId)->meal_id;

        DB::transaction(function () use ($planId, $mealId) {
            $meal = $this->lock($mealId);
            $plan = MenuPlan::find($planId) ?? throw new DomainException('This menu was already stopped.');
            $today = today()->toDateString();
            if (($plan->weekday === null ? $plan->starts_on : $plan->ends_on ?? $today) < $today) {
                throw new DomainException('This menu has already ended.');
            }

            if ($plan->starts_on >= $today) {
                $plan->delete();
            } else {
                $plan->update(['ends_on' => today()->subDay()->toDateString()]);
            }
            ActivityLog::record('menu_plan.stopped', null, "{$meal->name} menu "
                . ($plan->weekday === null ? 'on ' . $this->day($plan->starts_on) : 'every ' . $this->weekday($plan->weekday)) . ' stopped');
        }, 3);
    }

    /**
     * Copies what one week showed as single dates of another. Returns how many were copied.
     */
    public function copyWeek(string $fromMonday, string $toMonday): int
    {
        return DB::transaction(function () use ($fromMonday, $toMonday) {
            $meals = MealPrice::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $taken = MenuPlan::whereNull('weekday')->whereBetween('starts_on', [$toMonday, Carbon::parse($toMonday)->addDays(6)->toDateString()])
                ->get()->keyBy(fn ($plan) => "{$plan->meal_id}|{$plan->starts_on}");
            $offset = (int) Carbon::parse($fromMonday)->diffInDays($toMonday);

            $copied = 0;
            foreach (MenuCalendar::between($fromMonday, Carbon::parse($fromMonday)->addDays(6)->toDateString()) as $date => $cells) {
                $target = Carbon::parse($date)->addDays($offset)->toDateString();
                foreach (array_filter($cells) as $mealId => $cell) {
                    $ids = array_column(array_filter($cell['items'], fn ($item) => $item['available']), 'id');
                    if ($target < today()->toDateString() || $taken->has("$mealId|$target") || (! $ids && ! $cell['closed'])) {
                        continue;
                    }
                    $this->create($meals[$mealId], null, $target, null, $ids, $cell['closed'], $cell['note']);
                    $copied++;
                }
            }
            if ($copied) {
                ActivityLog::record('menu_plan.copied', null, "Menu of the week from {$this->day($fromMonday)} copied to {$this->day($toMonday)} ($copied meals)");
            }

            return $copied;
        }, 3);
    }

    private function create(MealPrice $meal, ?int $weekday, string $from, ?string $until, array $menuIds, bool $closed, ?string $note): void
    {
        $plan = MenuPlan::create([
            'meal_id' => $meal->id, 'weekday' => $weekday, 'starts_on' => $from, 'ends_on' => $until, 'closed' => $closed, 'note' => $note,
        ]);
        $plan->items()->attach(collect($menuIds)->mapWithKeys(fn ($id, $position) => [$id => ['position' => $position]])->all());
    }

    private function lock(int $mealId): MealPrice
    {
        return MealPrice::whereKey($mealId)->lockForUpdate()->first() ?? throw new DomainException('This meal is no longer available.');
    }

    private function weekday(int $weekday): string
    {
        return Carbon::getDays()[$weekday % 7];
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('j M Y');
    }
}

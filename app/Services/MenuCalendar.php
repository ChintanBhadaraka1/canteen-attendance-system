<?php

namespace App\Services;

use App\Models\MealPrice;
use App\Models\MenuPlan;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

// What is served on which date. Reads only.
class MenuCalendar
{
    /**
     * Every active meal of every date; null when nothing is planned.
     *
     * @return array<string, array<int, ?array>> [date][meal id] => plan_id, repeat, closed, note, items
     */
    public static function between(string $from, string $to): array
    {
        $meals = MealPrice::active()->pluck('id');
        $plans = self::plans($from, $to);

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $days[$date] = [];
            foreach ($meals as $id) {
                $plan = self::choose($plans->get($id, collect()), $date);
                $days[$date][$id] = $plan === null ? null : self::cell($plan);
            }
        }

        return $days;
    }

    /**
     * What a date shows once a plan is stopped; null when nothing.
     */
    public static function without(MenuPlan $plan, string $date): ?array
    {
        $plans = self::plans($date, $date)->get($plan->meal_id, collect())->where('id', '!=', $plan->id);
        $other = self::choose($plans, $date);

        return $other === null ? null : self::cell($other);
    }

    /**
     * Only what students may see; meals without a menu are left out.
     */
    public static function forStudents(string $from, string $to): array
    {
        $menus = self::between($from, $to);
        $prices = MealPrice::pricesBetween($from, $to);
        $meals = MealPrice::active()->get();

        return collect($menus)->map(fn ($cells, $date) => [
            'date' => $date,
            'meals' => $meals->map(function ($meal) use ($cells, $prices, $date) {
                $cell = $cells[$meal->id] ?? null;
                $items = array_values(array_filter($cell['items'] ?? [], fn ($item) => $item['available']));
                if ($cell === null || (! $items && ! $cell['closed'])) {
                    return null;
                }

                return [
                    'meal' => $meal->name,
                    'price' => $prices[$date][$meal->id]['price'],
                    'serve_from' => $meal->serve_from,
                    'serve_to' => $meal->serve_to,
                    'closed' => $cell['closed'],
                    'note' => $cell['note'],
                    'items' => array_map(fn ($item) => collect($item)->only('name', 'description', 'food_type', 'category', 'extra_price')->all(), $items),
                ];
            })->filter()->values()->all(),
        ])->values()->all();
    }

    private static function plans(string $from, string $to): Collection
    {
        return MenuPlan::with('items.foodType', 'items.category')
            ->where('starts_on', '<=', $to)
            ->where(fn ($q) => $q
                ->where(fn ($once) => $once->whereNull('weekday')->where('starts_on', '>=', $from))
                ->orWhere(fn ($weekly) => $weekly->whereNotNull('weekday')
                    ->where(fn ($end) => $end->whereNull('ends_on')->orWhere('ends_on', '>=', $from))))
            ->orderByDesc('id')->get()->toBase()->groupBy('meal_id');
    }

    // A single date wins over repeats; between repeats the newest wins.
    private static function choose(Collection $plans, string $date): ?MenuPlan
    {
        $weekday = Carbon::parse($date)->dayOfWeekIso;

        return $plans->first(fn ($plan) => $plan->weekday === null && $plan->starts_on === $date)
            ?? $plans->first(fn ($plan) => (int) $plan->weekday === $weekday && $plan->starts_on <= $date
                && ($plan->ends_on === null || $date <= $plan->ends_on));
    }

    private static function cell(MenuPlan $plan): array
    {
        return [
            'plan_id' => $plan->id,
            'repeat' => $plan->weekday === null ? 'once' : 'weekly',
            'closed' => $plan->closed,
            'note' => $plan->note,
            'items' => $plan->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'food_type' => $item->foodType?->name,
                'category' => $item->category?->name,
                'extra_price' => $item->is_extra === '1' ? (int) round((float) $item->price) : null,
                'available' => $item->available,
            ])->all(),
        ];
    }
}

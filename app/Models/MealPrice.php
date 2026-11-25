<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MealPrice extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'price',
        'active',
        'position',
        'serve_from',
        'serve_to',
    ];

    protected $casts = ['active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('position')->orderBy('id');
    }

    // Databases store 12:00 as 12:00:00.
    protected function serveFrom(): Attribute
    {
        return Attribute::make(get: fn ($time) => $time === null ? null : substr($time, 0, 5), set: fn ($time) => $time === null ? null : "$time:00");
    }

    protected function serveTo(): Attribute
    {
        return $this->serveFrom();
    }

    /**
     * The price charged on a date. When charging, call with the meal locked.
     */
    public function priceOn(string $date): int
    {
        return SpecialPrice::where('meal_id', $this->id)->between($date, $date)->value('price') ?? $this->normalPrice();
    }

    /**
     * @return array<int, array{price: int, normal: int, reason: ?string}> by meal id
     */
    public static function pricesOn(string $date): array
    {
        return static::pricesBetween($date, $date)[$date];
    }

    /**
     * @return array<string, array<int, array{price: int, normal: int, reason: ?string}>> by date and meal id
     */
    public static function pricesBetween(string $from, string $to): array
    {
        $meals = static::all();
        $specials = SpecialPrice::between($from, $to)->get()->groupBy('meal_id');

        $prices = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            $date = $day->toDateString();
            $prices[$date] = [];
            foreach ($meals as $meal) {
                $special = $specials->get($meal->id)?->first(fn ($row) => $row->from_date <= $date && $date <= $row->to_date);
                $prices[$date][$meal->id] = [
                    'price' => $special->price ?? $meal->normalPrice(), 'normal' => $meal->normalPrice(), 'reason' => $special?->reason,
                ];
            }
        }

        return $prices;
    }

    /**
     * Active meals as offered on the screens, with the price of that date.
     */
    public static function offeredOn(string $date): Collection
    {
        $prices = static::pricesOn($date);

        return static::active()->get()->map(fn ($meal) => $meal->only('id', 'name') + $prices[$meal->id])->toBase();
    }

    private function normalPrice(): int
    {
        return (int) round((float) $this->price);
    }
}

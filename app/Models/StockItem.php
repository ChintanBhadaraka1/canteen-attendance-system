<?php

namespace App\Models;

use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class StockItem extends Model
{
    const UNITS = ['kg' => ['kg' => 1000, 'g' => 1], 'litre' => ['litre' => 1000, 'ml' => 1], 'piece' => ['piece' => 1000]];

    // on_hand and value change only in StockService.
    protected $fillable = ['name', 'category_id', 'unit', 'low_level', 'active'];

    protected $attributes = ['active' => true, 'on_hand' => 0, 'value' => 0];

    protected $casts = ['low_level' => 'integer', 'on_hand' => 'integer', 'value' => 'integer', 'active' => 'boolean'];

    public function category()
    {
        return $this->belongsTo(MenuOption::class, 'category_id');
    }

    public function packs()
    {
        return $this->hasMany(StockPack::class)->orderBy('id');
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeLow($query)
    {
        return $query->where('active', true)->whereNotNull('low_level')->whereColumn('on_hand', '<=', 'low_level');
    }

    public function isLow(): bool
    {
        return $this->active && $this->low_level !== null && $this->on_hand <= $this->low_level;
    }

    /**
     * What can be entered, with the thousandths in one of it.
     *
     * @return array<string, int>
     */
    public function sizes(): array
    {
        return self::UNITS[$this->unit] + $this->packs->where('active', true)->mapWithKeys(fn ($pack) => ["pack:{$pack->id}" => $pack->size])->all();
    }

    // "2.5 kg", "3 pieces"
    public function amount(int $quantity): string
    {
        return Quantity::show($quantity) . ' ' . $this->unit . ($this->unit !== 'kg' && abs($quantity) !== 1000 ? 's' : '');
    }

    /**
     * The part of a value that belongs to n of a quantity, rounded half up.
     */
    public static function share(int $value, int $n, int $of): int
    {
        return intdiv(2 * $value * $n + $of, 2 * $of);
    }

    /**
     * The latest purchase, or opening if asked for, of each item that is not cancelled.
     *
     * @return Collection<int, StockMovement> by item id
     */
    public static function latestBought(iterable $ids, array $types = ['purchase']): Collection
    {
        $latest = StockMovement::whereIn('stock_item_id', $ids)
            ->whereHas('entry', fn ($q) => $q->whereIn('type', $types)->whereDoesntHave('cancellation'))
            ->groupBy('stock_item_id')->selectRaw('max(id)');

        return StockMovement::whereIn('id', $latest)->get()->keyBy('stock_item_id');
    }

    /**
     * For the forms: what can be chosen and entered.
     */
    public function choice(): array
    {
        return $this->only('id', 'name', 'unit', 'on_hand', 'active', 'category_id') + [
            'units' => collect(self::UNITS[$this->unit])->map(fn ($size, $key) => ['key' => $key, 'label' => $key, 'size' => $size])
                ->concat($this->packs->where('active', true)->map(fn ($pack) => ['key' => "pack:{$pack->id}", 'label' => $pack->label($this->unit), 'size' => $pack->size]))
                ->values()->all(),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuOption extends Model
{
    // Kind => [name of the list, what uses it]
    const KINDS = [
        'food_type' => ['food types', 'menu item'],
        'category' => ['categories', 'menu item'],
        'stock_category' => ['stock categories', 'stock item'],
        'shop' => ['shops', 'purchase'],
        'waste_reason' => ['waste reasons', 'waste record'],
        'expense_type' => ['expense types', 'expense'],
        'staff_job' => ['staff jobs', 'staff record'],
    ];

    protected $fillable = ['kind', 'name', 'active', 'position'];

    protected $attributes = ['active' => true];

    protected $casts = ['active' => 'boolean'];

    public function scopeOf($query, string $kind)
    {
        return $query->where('kind', $kind)->orderBy('position')->orderBy('id');
    }

    public function usedBy(): int
    {
        return match ($this->kind) {
            'stock_category' => StockItem::where('category_id', $this->id)->count(),
            'shop' => StockEntry::where('shop_id', $this->id)->count(),
            'waste_reason' => StockEntry::where('reason_id', $this->id)->count(),
            'expense_type' => Expense::where('type_id', $this->id)->count(),
            'staff_job' => Staff::where('job_id', $this->id)->count(),
            default => Menus::where("{$this->kind}_id", $this->id)->count(),
        };
    }

    public function isUsed(): bool
    {
        return $this->usedBy() > 0;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockEntry extends Model
{
    const TYPES = [
        'opening' => 'Opening stock', 'purchase' => 'Purchase', 'use' => 'Used', 'waste' => 'Wasted', 'count' => 'Stock count', 'cancel' => 'Cancelled',
    ];

    const METHODS = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank'];

    // Written only by StockService.
    protected $guarded = [];

    protected $casts = ['discount' => 'integer', 'charges' => 'integer', 'total' => 'integer'];

    public function movements()
    {
        return $this->hasMany(StockMovement::class)->orderBy('id');
    }

    public function cancellation()
    {
        return $this->hasOne(StockEntry::class, 'cancels_id');
    }

    public function reason()
    {
        return $this->belongsTo(MenuOption::class, 'reason_id');
    }

    public function scopeNotCancelled($query)
    {
        return $query->where('type', '!=', 'cancel')->whereDoesntHave('cancellation');
    }

    public function isCancellable(): bool
    {
        return in_array($this->type, ['purchase', 'opening', 'use', 'waste'], true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    // Written only by StockService, and never changed.
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer', 'cost' => 'integer', 'after_quantity' => 'integer', 'entered_quantity' => 'integer', 'unit_size' => 'integer',
    ];

    public function entry()
    {
        return $this->belongsTo(StockEntry::class, 'stock_entry_id');
    }

    public function item()
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}

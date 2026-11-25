<?php

namespace App\Models;

use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

class StockPack extends Model
{
    protected $guarded = [];

    protected $attributes = ['active' => true];

    protected $casts = ['size' => 'integer', 'active' => 'boolean'];

    // "Bag × 25 kg"
    public function label(string $unit): string
    {
        return "{$this->name} × " . Quantity::show($this->size) . " $unit";
    }
}

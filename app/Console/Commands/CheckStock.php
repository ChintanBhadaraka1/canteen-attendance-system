<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckStock extends Command
{
    protected $signature = 'canteen:check-stock';

    protected $description = 'Check stock totals, movements and purchases. Reads only; prints IDs and numbers, no names.';

    public function handle(): int
    {
        // shortcut: loads every row, use chunks if movements reach hundreds of thousands.
        $items = DB::table('stock_items')->orderBy('id')->get();
        $entries = DB::table('stock_entries')->orderBy('id')->get()->keyBy('id');
        $movements = DB::table('stock_movements')->orderBy('id')->get();
        $byItem = $movements->groupBy('stock_item_id');
        $byEntry = $movements->groupBy('stock_entry_id');
        $problems = [];

        foreach ($items as $item) {
            $rows = $byItem[$item->id] ?? collect();
            $quantity = (int) $rows->sum('quantity');
            $value = (int) $rows->sum('cost');
            if ((int) $item->on_hand !== $quantity) {
                $problems[] = "item {$item->id}: on hand {$item->on_hand}, movements $quantity";
            }
            if ((int) $item->value !== $value) {
                $problems[] = "item {$item->id}: value {$item->value}, movements $value";
            }
            if ($item->on_hand < 0 || $item->value < 0) {
                $problems[] = "item {$item->id}: below zero";
            }
            if ((int) $item->on_hand === 0 && (int) $item->value !== 0) {
                $problems[] = "item {$item->id}: value without stock";
            }
            $left = 0;
            foreach ($rows as $row) {
                $left += (int) $row->quantity;
                if ((int) $row->after_quantity !== $left) {
                    $problems[] = "movement {$row->id}: balance {$row->after_quantity}, expected $left";
                }
            }
        }

        foreach ($entries as $entry) {
            $rows = $byEntry[$entry->id] ?? collect();
            if ($rows->isEmpty()) {
                $problems[] = "entry {$entry->id}: no movements";
            }
            if ($entry->type === 'purchase') {
                $total = (int) $rows->sum('cost') - (int) $entry->discount + (int) $entry->charges;
                if ((int) $entry->total !== $total) {
                    $problems[] = "entry {$entry->id}: total {$entry->total}, expected $total";
                }
            }
            if ($entry->type === 'cancel') {
                $sums = fn ($list) => $list->groupBy('stock_item_id')->map(fn ($group) => (int) $group->sum('quantity'))->sortKeys();
                $original = $sums($byEntry[$entry->cancels_id] ?? collect())->map(fn ($quantity) => -$quantity)->all();
                if ($sums($rows)->all() !== $original) {
                    $problems[] = "entry {$entry->id}: quantities differ from entry {$entry->cancels_id}";
                }
            }
        }

        foreach ($problems as $problem) {
            $this->line($problem);
        }
        $this->info(count($problems) . ' problems in ' . $items->count() . ' items and ' . $entries->count() . ' entries.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}

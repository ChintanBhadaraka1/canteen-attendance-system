<?php

namespace App\Services;

use App\Exceptions\StockException;
use App\Models\ActivityLog;
use App\Models\MenuOption;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Support\Quantity;
use App\Support\Rupees;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Every write to stock. Quantities are whole thousandths and money is whole paise; see Quantity and Rupees.
class StockService
{
    const MOST = 1_000_000_000; // 1,000,000 units, and ₹1,00,00,000: keeps every product inside 64 bits.

    /**
     * @param  array  $entry  type (purchase, use, waste), date, token, and the fields of that type
     * @param  array<int, array{item: int, quantity: string, unit: string, amount?: string}>  $rows
     */
    public function save(array $entry, array $rows): StockEntry
    {
        return $this->once($entry, fn () => $this->locked(array_column($rows, 'item'), function (Collection $items) use ($entry, $rows) {
            if ($saved = $this->saved($entry['type'], $entry['token'])) {
                return $saved;
            }
            $entry = $this->checked($entry);
            $lines = [];
            foreach ($rows as $key => $row) {
                $item = $this->itemOf($items, $row, $key);
                if ($entry['type'] === 'purchase' && ! $item->active) {
                    throw new StockException($this->row($key) . "{$item->name} is turned off, so it cannot be bought.", "rows.$key.item");
                }
                $lines[$key] = ['item' => $item] + $this->quantity($item, $row, $key)
                    + ['amount' => $entry['type'] === 'purchase' ? Rupees::parse($row['amount']) : 0];
            }
            if ($entry['type'] === 'purchase') {
                $entry['total'] = $this->total($entry, array_sum(array_column($lines, 'amount')));
            }
            unset($entry['paper_total'], $entry['different_bill']);

            $saved = $this->create($entry);
            foreach ($lines as $key => $line) {
                if ($entry['type'] === 'purchase') {
                    $this->move($saved, $line['item'], $line['quantity'], $line['amount'], $line, "rows.$key.quantity", $this->row($key));
                } else {
                    $this->take($saved, $line['item'], $line['quantity'], $line, "rows.$key.quantity", $this->row($key));
                }
            }
            $this->record($saved, $lines);

            return $saved;
        }));
    }

    /**
     * New items, some with opening stock.
     *
     * @param  array<int, array{name: string, category_id: ?int, unit: string, low_level: ?int, quantity: ?string, amount: ?string}>  $rows
     * @return Collection<int, StockItem>
     */
    public function addItems(array $entry, array $rows): Collection
    {
        return DB::transaction(function () use ($entry, $rows) {
            $items = new Collection;
            $saved = null;
            foreach ($rows as $key => $row) {
                try {
                    $item = StockItem::create(array_intersect_key($row, array_flip(['name', 'category_id', 'unit', 'low_level'])));
                } catch (UniqueConstraintViolationException) {
                    throw new StockException($this->row($key) . "{$row['name']} is already in the list.", "rows.$key.name");
                }
                $items->push($item);
                ActivityLog::record('stock_item.added', null, "{$item->name} added to stock", ['stock_item_id' => $item->id]);
                if ($row['quantity'] === null) {
                    continue;
                }
                $saved ??= $this->create($entry + ['type' => 'opening']);
                $line = $this->quantity($item, ['quantity' => $row['quantity'], 'unit' => $item->unit], $key);
                $this->move($saved, $item, $line['quantity'], Rupees::parse($row['amount']), $line, "rows.$key.quantity", $this->row($key));
                ActivityLog::record('stock.opening', null, "Opening stock of {$item->name}: {$item->amount($line['quantity'])}", ['stock_entry_id' => $saved->id]);
            }

            return $items;
        }, 3);
    }

    /**
     * @param  array<int, array{item: int, quantity: string, unit: string, expected: int}>  $rows
     * @return ?StockEntry null when everything matches
     */
    public function count(array $entry, array $rows): ?StockEntry
    {
        $entry = ['type' => 'count'] + $entry;

        return $this->once($entry, fn () => $this->locked(array_column($rows, 'item'), function (Collection $items) use ($entry, $rows) {
            if ($saved = $this->saved('count', $entry['token'])) {
                return $saved;
            }
            $lines = [];
            $stale = [];
            foreach ($rows as $key => $row) {
                $item = $this->itemOf($items, $row, $key);
                if ($item->on_hand !== $row['expected']) {
                    $stale[] = "{$item->name} now has {$item->amount($item->on_hand)}";
                }
                $line = $this->quantity($item, $row, $key, 0);
                if ($line['quantity'] !== $item->on_hand) {
                    $lines[$key] = ['item' => $item] + $line;
                }
            }
            if ($stale) {
                throw new StockException('The stock has changed: ' . implode(', ', $stale) . '. Please check and save again.');
            }
            if (! $lines) {
                return null;
            }
            if ($entry['reason_text'] === null) {
                throw new StockException('Write why the count is different.', 'reason_text');
            }

            $saved = $this->create($entry);
            foreach ($lines as $key => $line) {
                $item = $line['item'];
                $difference = $line['quantity'] - $item->on_hand;
                if ($difference < 0) {
                    $this->take($saved, $item, -$difference, $line, "rows.$key.quantity", '');
                } else {
                    $this->move($saved, $item, $difference, $this->valueUp($item, $difference), $line, "rows.$key.quantity", '');
                }
            }
            $this->record($saved, $lines);

            return $saved;
        }));
    }

    public function cancel(int $entryId, string $reason): StockEntry
    {
        // The movements of an entry never change.
        $itemIds = StockMovement::where('stock_entry_id', StockEntry::findOrFail($entryId)->id)->pluck('stock_item_id')->all();

        return $this->locked($itemIds, function (Collection $items) use ($entryId, $reason) {
            $entry = StockEntry::whereKey($entryId)->lockForUpdate()->firstOrFail();
            if ($entry->type === 'cancel') {
                throw new StockException('A cancellation cannot be cancelled.', 'reason');
            }
            if (! $entry->isCancellable()) {
                throw new StockException('A stock count cannot be cancelled. Do a new count.', 'reason');
            }
            if ($entry->cancellation()->exists()) {
                throw new StockException('This was already cancelled.', 'reason');
            }

            $cancel = $this->create(['type' => 'cancel', 'date' => $entry->date, 'token' => (string) Str::uuid(), 'reason_text' => $reason, 'cancels_id' => $entry->id]);
            foreach ($entry->movements()->get()->reverse() as $movement) {
                $item = $items[$movement->stock_item_id];
                $left = $item->on_hand - $movement->quantity;
                if ($left < 0) {
                    throw new StockException("Only {$item->amount($item->on_hand)} of {$item->name} left, so this cannot be cancelled.", 'reason');
                }
                $cost = match (true) {
                    $movement->quantity < 0 => -$movement->cost,
                    $left === 0 => -$item->value,
                    default => -min($movement->cost, $item->value),
                };
                $this->move($cancel, $item, -$movement->quantity, $cost, [
                    'entered' => $movement->entered, 'entered_quantity' => $movement->entered_quantity,
                    'entered_unit' => $movement->entered_unit, 'unit_size' => $movement->unit_size,
                ], 'reason', '');
            }
            ActivityLog::record('stock.cancelled', null,
                StockEntry::TYPES[$entry->type] . " of {$this->day($entry->date)} (" . $entry->movements()->with('item')->get()->map(fn ($row) => $row->item->name)->unique()->implode(', ') . ") cancelled: $reason",
                ['stock_entry_id' => $entry->id]);

            return $cancel;
        });
    }

    /**
     * The entry already saved by this user with this token.
     */
    public function saved(string $type, string $token): ?StockEntry
    {
        $saved = StockEntry::where('token', $token)->first();
        if ($saved !== null && ($saved->type !== $type || $saved->created_by !== auth()->id())) {
            throw new StockException('This was already saved. Reload the page and try again.');
        }

        return $saved;
    }

    // Two requests with one token can both pass the look-up; the unique key stops the second.
    private function once(array $entry, Closure $save): ?StockEntry
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException $e) {
            return $this->saved($entry['type'], $entry['token']) ?? throw $e;
        }
    }

    /**
     * @param  Closure(Collection<int, StockItem>): mixed  $work  receives the locked items by id
     */
    private function locked(array $itemIds, Closure $work): mixed
    {
        return DB::transaction(function () use ($itemIds, $work) {
            // First query, always in ID order: an earlier read would fix an old MariaDB snapshot.
            $items = StockItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            return $work($items->load('packs'));
        }, 3);
    }

    // The parts of an entry that depend on other rows. Locks its shop or reason, so they cannot be deleted meanwhile.
    private function checked(array $entry): array
    {
        if ($entry['shop_id'] ?? null) {
            // Not shared, and a locking read below: two purchases with one bill number must see each other.
            $shop = MenuOption::where('kind', 'shop')->whereKey($entry['shop_id'])->lockForUpdate()->first();
            if (! $shop?->active) {
                throw new StockException('Choose a shop from the list.', 'shop_id');
            }
            $entry['shop_name'] = $shop->name;
            $same = $entry['bill_number'] === null || $entry['different_bill'] ? null
                : StockEntry::notCancelled()->where('type', 'purchase')->where('shop_id', $shop->id)->where('bill_number', $entry['bill_number'])->sharedLock()->first();
            if ($same) {
                throw new StockException("Bill number {$entry['bill_number']} of {$shop->name} was already saved on {$this->day($same->date)}.", 'different_bill');
            }
        }
        if ($entry['type'] === 'waste') {
            $reason = MenuOption::where('kind', 'waste_reason')->whereKey($entry['reason_id'])->sharedLock()->first();
            if (! $reason?->active) {
                throw new StockException('Choose a reason from the list.', 'reason_id');
            }
            if (mb_strtolower($reason->name) === 'other' && $entry['reason_text'] === null) {
                throw new StockException('Write what happened.', 'reason_text');
            }
        }

        return $entry;
    }

    private function total(array $entry, int $sum): int
    {
        $total = $sum - $entry['discount'] + $entry['charges'];
        if ($total < 0) {
            throw new StockException('The discount is more than the amounts.', 'discount');
        }
        if ($entry['paper_total'] !== null && $entry['paper_total'] !== $total) {
            throw new StockException('The rows add up to ' . Rupees::format($total) . ' but the paper bill says ' . Rupees::format($entry['paper_total']) . '.', 'paper_total');
        }

        return $total;
    }

    private function itemOf(Collection $items, array $row, int $key): StockItem
    {
        return $items[$row['item']] ?? throw new StockException($this->row($key) . 'Choose an item from the list.', "rows.$key.item");
    }

    /**
     * @return array{quantity: int, entered: string, entered_quantity: int, entered_unit: string, unit_size: int}
     */
    private function quantity(StockItem $item, array $row, int $key, int $least = 1): array
    {
        $size = $item->sizes()[$row['unit']] ?? throw new StockException($this->row($key) . 'Choose a unit from the list.', "rows.$key.unit");
        $typed = Quantity::parse($row['quantity']);
        $field = "rows.$key.quantity";
        if ($typed === null || $typed < $least) {
            throw new StockException($this->row($key) . 'Enter a quantity, for example 2.5.', $field);
        }
        // Before multiplying, so the product stays a whole number.
        if ($typed > intdiv(self::MOST * 1000, $size)) {
            throw new StockException($this->row($key) . 'This quantity is too large.', $field);
        }
        if ($typed * $size % 1000 !== 0) {
            throw new StockException($this->row($key) . 'Too many decimals.', $field);
        }
        $pack = str_starts_with($row['unit'], 'pack:') ? $item->packs->find((int) substr($row['unit'], 5)) : null;

        return [
            'quantity' => intdiv($typed * $size, 1000),
            'entered' => Quantity::show($typed) . ' ' . ($pack ? $pack->label($item->unit) : $row['unit']),
            'entered_quantity' => $typed, 'entered_unit' => $row['unit'], 'unit_size' => $size,
        ];
    }

    private function take(StockEntry $entry, StockItem $item, int $quantity, array $line, string $field, string $row): void
    {
        if ($quantity > $item->on_hand) {
            throw new StockException("{$row}Only {$item->amount($item->on_hand)} of {$item->name} left. Do a stock count if this is wrong.", $field);
        }
        $cost = $quantity === $item->on_hand ? $item->value : StockItem::share($item->value, $quantity, $item->on_hand);
        $this->move($entry, $item, -$quantity, -$cost, $line, $field, $row);
    }

    // The average of what is there; from nothing, the rate of the latest purchase or opening.
    private function valueUp(StockItem $item, int $quantity): int
    {
        if ($item->on_hand > 0) {
            return StockItem::share($item->value, $quantity, $item->on_hand);
        }
        $last = StockItem::latestBought([$item->id], ['purchase', 'opening'])->first();

        return $last ? StockItem::share($last->cost, $quantity, $last->quantity) : 0;
    }

    // The one place where stock changes.
    private function move(StockEntry $entry, StockItem $item, int $quantity, int $cost, array $line, string $field, string $row): void
    {
        $item->on_hand += $quantity;
        $item->value += $cost;
        if ($item->on_hand > self::MOST) {
            throw new StockException("{$row}{$item->name} would be more than {$item->amount(self::MOST)}.", $field);
        }
        if ($item->value > self::MOST) {
            throw new StockException("{$row}{$item->name} would be worth more than " . Rupees::format(self::MOST) . '.', $field);
        }
        $item->save();
        StockMovement::create([
            'stock_entry_id' => $entry->id, 'stock_item_id' => $item->id, 'quantity' => $quantity, 'cost' => $cost, 'after_quantity' => $item->on_hand,
        ] + array_intersect_key($line, array_flip(['entered', 'entered_quantity', 'entered_unit', 'unit_size'])));
    }

    private function create(array $entry): StockEntry
    {
        return StockEntry::create($entry + ['created_by' => auth()->id(), 'user_name' => auth()->user()->full_name ?? 'System']);
    }

    private function record(StockEntry $entry, array $lines): void
    {
        $names = implode(', ', array_map(fn ($line) => "{$line['item']->name} " . $line['entered'], $lines));
        [$action, $text] = match ($entry->type) {
            'purchase' => ['stock.purchase', 'Purchase' . ($entry->shop_name ? " from {$entry->shop_name}" : '') . ' (' . Rupees::format($entry->total) . ')'],
            'use' => ['stock.used', 'Used'],
            'waste' => ['stock.wasted', 'Wasted'],
            'count' => ['stock.counted', 'Counted'],
        };
        ActivityLog::record($action, null, "$text on {$this->day($entry->date)}: $names", ['stock_entry_id' => $entry->id]);
    }

    private function row(int $key): string
    {
        return 'Row ' . ($key + 1) . ': ';
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('j F Y');
    }
}

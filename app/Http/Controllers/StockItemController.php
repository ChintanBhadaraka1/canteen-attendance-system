<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Concerns\ReadsStockRows;
use App\Models\ActivityLog;
use App\Models\MenuOption;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockPack;
use App\Services\StockService;
use App\Support\Quantity;
use App\Support\Rupees;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StockItemController extends Controller
{
    use ListsRecords, ReadsStockRows;

    const TAKEN = 'This name is already in the list.';

    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name', 'on_hand', 'value'], 'name');
        $category = MenuOption::of('stock_category')->find((int) $this->text($request, 'category'));
        $filters['category'] = $category?->id ?? '';
        $show = $this->text($request, 'show');
        $filters['show'] = in_array($show, ['low', 'off'], true) ? $show : '';

        $query = StockItem::with('category')->orderBy($filters['sort'], $filters['dir'])->orderBy('id');
        $this->searchIn($query, ['name'], $filters['search']);
        $query->when($category, fn ($q) => $q->where('category_id', $category->id));
        $filters['show'] === 'low' ? $query->low() : $query->where('active', $filters['show'] !== 'off');

        $page = $this->pageOf($query);
        $last = StockItem::latestBought($page->pluck('id'));

        return Inertia::render('Stock/Index', [
            'items' => $page->through(fn ($item) => $this->row($item) + ['last' => $this->rate($last[$item->id] ?? null)]),
            'filters' => $filters,
            'categories' => $this->categories(),
            'low' => StockItem::low()->count(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Stock/Create', ['categories' => $this->categories(), 'token' => (string) Str::uuid()]);
    }

    public function store(Request $request, StockService $stock)
    {
        $this->dropEmptyRows($request, ['name', 'low_level', 'quantity', 'amount']);
        $token = $request->validate(['token' => $this->entryRules()['token']])['token'];
        // A repeat after a lost answer, before its own names count as taken.
        if ($this->orRefuse(fn () => $stock->saved('opening', $token))) {
            return redirect()->route('stock.index')->with('success', 'Items added.');
        }

        $rules = $this->itemRules();
        $input = $request->validate($this->rowRules(50, [
            'name' => ['bail', ...$rules['name'], fn ($field, $name, $fail) => $this->nameTaken($name) && $fail($this->rowName($field) . "$name is already in the list.")],
            'category_id' => $rules['category_id'],
            'unit' => $rules['unit'],
            'low_level' => ['bail', 'nullable', ...$this->quantityRule(0)],
            'quantity' => ['bail', 'nullable', 'required_with:rows.*.amount', ...$this->quantityRule()],
            'amount' => ['bail', 'nullable', 'required_with:rows.*.quantity', ...$this->moneyRule()],
        ]), [...array_fill_keys(['rows.*.name.required', 'rows.*.name.string', 'rows.*.name.min', 'rows.*.name.max'], 'Row :position: enter a name of 2 to 60 letters.'), 'rows.*.category_id.*' => 'Row :position: choose a category from the list.',
            'rows.*.*.required_with' => 'Row :position: enter both the opening quantity and its value, or neither.'] + $this->messages());

        $seen = [];
        foreach ($input['rows'] as $key => $row) {
            $name = mb_strtolower($row['name']);
            if (isset($seen[$name])) {
                throw ValidationException::withMessages(["rows.$key.name" => 'Row ' . ($key + 1) . ": {$row['name']} is in two rows."]);
            }
            $seen[$name] = true;
        }

        $rows = array_map(fn ($row) => [
            'name' => $row['name'], 'category_id' => $this->id($row['category_id'] ?? null), 'unit' => $row['unit'],
            'low_level' => isset($row['low_level']) ? Quantity::parse($row['low_level']) : null,
            'quantity' => isset($row['quantity']) ? (string) $row['quantity'] : null, 'amount' => isset($row['amount']) ? (string) $row['amount'] : null,
        ], $input['rows']);

        $items = $this->orRefuse(fn () => $stock->addItems(['date' => today()->toDateString(), 'token' => $token], $rows));

        return redirect()->route('stock.index')->with('success', $items->count() . ' ' . Str::plural('item', $items->count()) . ' added.');
    }

    public function show(string $item)
    {
        $item = StockItem::with('category', 'packs')->findOrFail($item);

        return Inertia::render('Stock/Show', [
            'item' => $this->row($item) + [
                'average' => $item->on_hand > 0 ? StockItem::share($item->value, 1000, $item->on_hand) : null,
                'last' => $this->rate(StockItem::latestBought([$item->id])->first()),
                'packs' => $item->packs->map(fn ($pack) => $pack->only('id', 'active') + ['label' => $pack->label($item->unit)]),
            ],
            'rows' => $this->pageOf($item->movements()->with('entry.cancellation', 'entry.reason', 'entry.movements.item')->orderByDesc('id'))
                ->through(fn ($row) => $row->only('id', 'quantity', 'cost', 'after_quantity', 'entered') + $this->entry($row->entry)),
        ]);
    }

    private function entry(StockEntry $entry): array
    {
        return $entry->only('type', 'date', 'note', 'shop_name', 'user_name') + [
            'entry_id' => $entry->id,
            'label' => StockEntry::TYPES[$entry->type],
            'reason' => implode(': ', array_filter([$entry->reason?->name, $entry->reason_text])),
            'cancelled' => $entry->cancellation !== null,
            'cancellable' => $entry->isCancellable() && $entry->cancellation === null,
            'names' => $entry->movements->map(fn ($row) => $row->item->name)->unique()->implode(', '),
        ];
    }

    public function edit(string $item)
    {
        $item = StockItem::findOrFail($item);

        return Inertia::render('Stock/Edit', [
            'item' => $item->only('id', 'name', 'category_id', 'unit') + [
                'low_level' => $item->low_level === null ? '' : Quantity::show($item->low_level),
                'moved' => $item->movements()->exists(),
            ],
            'categories' => $this->categories($item->category_id),
        ]);
    }

    public function update(Request $request, string $item)
    {
        $row = StockItem::findOrFail($item);
        $rules = $this->itemRules($row->category_id);
        $input = $request->validate([
            'name' => ['bail', ...$rules['name'], fn ($field, $name, $fail) => $this->nameTaken($name, $row->id) && $fail(self::TAKEN)],
            'category_id' => $rules['category_id'],
            'unit' => $rules['unit'],
            'low_level' => ['bail', 'nullable', ...$this->quantityRule(0)],
        ]);

        try {
            DB::transaction(function () use ($row, $input) {
                $item = StockItem::whereKey($row->id)->lockForUpdate()->firstOrFail();
                $item->fill([
                    'name' => $input['name'], 'unit' => $input['unit'], 'category_id' => $this->id($input['category_id'] ?? null),
                    'low_level' => isset($input['low_level']) ? Quantity::parse($input['low_level']) : null,
                ]);
                if ($item->isDirty('unit') && $item->movements()->exists()) {
                    throw ValidationException::withMessages(['unit' => 'This item already has stock entries, so its unit cannot change.']);
                }
                $summary = ActivityLog::describe($item, ['name' => 'name', 'category_id' => 'category', 'unit' => 'unit', 'low_level' => 'low level']);
                if ($item->isDirty()) {
                    $item->save();
                    ActivityLog::record('stock_item.changed', null, $summary, ['stock_item_id' => $item->id]);
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::TAKEN]);
        }

        return redirect()->route('stock.show', $row->id)->with('success', 'Change saved.');
    }

    public function active(Request $request, string $item)
    {
        $id = StockItem::findOrFail($item)->id;
        $on = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        $name = DB::transaction(fn () => $this->turn(StockItem::whereKey($id)->lockForUpdate()->firstOrFail(), $on, fn ($row) => $row->name), 3);

        return redirect()->route('stock.show', $id)->with('success', "$name turned " . ($on ? 'on.' : 'off.'));
    }

    public function addPack(Request $request, string $item)
    {
        $item = StockItem::findOrFail($item);
        $input = $request->validate([
            'name' => ['bail', 'required', 'string', 'max:20', function ($field, $name, $fail) {
                if (in_array(mb_strtolower($name), ['kg', 'g', 'litre', 'ml', 'piece'], true)) {
                    $fail('This is already a unit. Choose another name.');
                }
            }],
            'size' => ['bail', 'required', ...$this->quantityRule()],
        ]);
        $size = Quantity::parse($input['size']);
        if ($size > StockService::MOST) {
            throw ValidationException::withMessages(['size' => 'This size is too large.']);
        }

        try {
            DB::transaction(function () use ($item, $input, $size) {
                $pack = StockPack::create(['stock_item_id' => $item->id, 'name' => $input['name'], 'size' => $size]);
                ActivityLog::record('stock_item.changed', null, "{$item->name}: {$pack->label($item->unit)} added", ['stock_item_id' => $item->id]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This pack is already in the list.']);
        }

        return redirect()->route('stock.show', $item->id)->with('success', 'Pack added.');
    }

    public function packActive(Request $request, string $pack)
    {
        $row = StockPack::findOrFail($pack);
        $on = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        DB::transaction(function () use ($row, $on) {
            $item = StockItem::whereKey($row->stock_item_id)->lockForUpdate()->firstOrFail();
            $this->turn(StockPack::findOrFail($row->id), $on, fn ($pack) => "{$item->name}: {$pack->label($item->unit)}", $item->id);
        }, 3);

        return redirect()->route('stock.show', $row->stock_item_id)->with('success', 'Change saved.');
    }

    public function shoppingList()
    {
        return Inertia::render('Stock/ShoppingList', [
            'items' => StockItem::low()->with('category')->orderBy('name')->get()->map(fn ($item) => $this->row($item)),
        ]);
    }

    private function turn(StockItem|StockPack $row, bool $on, callable $name, ?int $itemId = null): string
    {
        if ($row->active !== $on) {
            $row->update(['active' => $on]);
            ActivityLog::record('stock_item.changed', null, $name($row) . ' turned ' . ($on ? 'on' : 'off'), ['stock_item_id' => $itemId ?? $row->id]);
        }

        return $name($row);
    }

    private function row(StockItem $item): array
    {
        return $item->only('id', 'name', 'unit', 'on_hand', 'value', 'low_level', 'active') + ['category' => $item->category?->name, 'low' => $item->isLow()];
    }

    // Paise for one kg, litre or piece.
    private function rate($movement): ?int
    {
        return $movement ? StockItem::share($movement->cost, 1000, $movement->quantity) : null;
    }

    private function itemRules(?int $current = null): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'category_id' => ['bail', 'nullable', 'integer', fn ($field, $id, $fail) => MenuOption::where('kind', 'stock_category')->whereKey($id)
                ->where(fn ($q) => $q->where('active', true)->orWhere('id', $current))->exists() ?: $fail('Choose one from the list.')],
            'unit' => ['bail', 'required', 'string', 'in:' . implode(',', array_keys(StockItem::UNITS))],
        ];
    }

    private function nameTaken(string $name, ?int $except = null): bool
    {
        return StockItem::when($except, fn ($q) => $q->whereKeyNot($except))->pluck('name')
            ->contains(fn ($other) => mb_strtolower($other) === mb_strtolower($name));
    }

    private function id(mixed $id): ?int
    {
        return $id === null || $id === '' ? null : (int) $id;
    }

    private function categories(?int $current = null): array
    {
        return MenuOption::of('stock_category')->get()->filter(fn ($option) => $option->active || $option->id === $current)
            ->map(fn ($option) => $option->only('id', 'name'))->values()->all();
    }
}

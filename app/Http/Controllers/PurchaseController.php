<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Concerns\ReadsStockRows;
use App\Models\MenuOption;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\Quantity;
use App\Support\Rupees;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PurchaseController extends Controller
{
    use ListsRecords, ReadsStockRows;

    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['date'], 'date', 'desc') + $this->optionalDates($request);
        $shops = MenuOption::of('shop')->get();
        $items = StockItem::orderBy('name')->get();
        $filters['shop'] = $shops->find((int) $this->text($request, 'shop'))?->id ?? '';
        $filters['item'] = $items->find((int) $this->text($request, 'item'))?->id ?? '';
        $show = $this->text($request, 'show');
        $filters['show'] = in_array($show, ['saved', 'cancelled'], true) ? $show : '';

        $query = StockEntry::where('type', 'purchase')->with('cancellation')->withCount('movements')
            ->orderBy('date', $filters['dir'])->orderBy('id', $filters['dir']);
        $this->searchIn($query, ['shop_name', 'bill_number', 'note'], $filters['search']);
        $query->when($filters['from'], fn ($q, $date) => $q->where('date', '>=', $date))
            ->when($filters['to'], fn ($q, $date) => $q->where('date', '<=', $date))
            ->when($filters['shop'], fn ($q, $id) => $q->where('shop_id', $id))
            ->when($filters['item'], fn ($q, $id) => $q->whereHas('movements', fn ($m) => $m->where('stock_item_id', $id)))
            ->when($filters['show'] === 'saved', fn ($q) => $q->whereDoesntHave('cancellation'))
            ->when($filters['show'] === 'cancelled', fn ($q) => $q->whereHas('cancellation'));

        return Inertia::render('Purchases/Index', [
            'purchases' => $this->pageOf($query)->through(fn ($row) => $this->row($row) + ['count' => $row->movements_count]),
            'filters' => $filters,
            'shops' => $shops->map->only('id', 'name'),
            'items' => $items->map->only('id', 'name'),
        ]);
    }

    public function create(Request $request)
    {
        $items = StockItem::with('packs')->where('active', true)->orderBy('name')->get();
        $last = StockItem::latestBought($items->pluck('id'));
        $shops = MenuOption::of('shop')->where('active', true)->get();
        $from = StockEntry::where('type', 'purchase')->with('movements.item')->find((int) $this->text($request, 'from'));

        $rows = [];
        $gone = [];
        foreach ($from->movements ?? [] as $movement) {
            $item = $items->find($movement->stock_item_id);
            if ($item && isset($item->sizes()[$movement->entered_unit])) {
                $rows[] = ['item' => $item->id, 'quantity' => Quantity::show($movement->entered_quantity), 'unit' => $movement->entered_unit];
            } else {
                $gone[] = $movement->item->name . ($item ? " ({$movement->entered})" : '');
            }
        }

        return Inertia::render('Purchases/Form', [
            'items' => $items->map(fn ($item) => $item->choice() + ['last' => $last->has($item->id) ? $last[$item->id]->only('cost', 'quantity') : null])->values(),
            'shops' => $shops->map->only('id', 'name'),
            'rows' => $rows,
            'shop' => $from && $shops->contains('id', $from->shop_id) ? $from->shop_id : null,
            'gone' => $gone,
            'token' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, StockService $stock)
    {
        $this->dropEmptyRows($request, ['item', 'quantity', 'amount']);
        $optional = ['bail', 'nullable', ...$this->moneyRule()];
        $input = $request->validate($this->entryRules() + [
            'shop_id' => ['bail', 'nullable', 'integer'],
            'bill_number' => ['nullable', 'string', 'max:40'],
            'different_bill' => ['nullable', 'boolean'],
            'method' => ['bail', 'required', 'string', 'in:' . implode(',', array_keys(StockEntry::METHODS))],
            'discount' => $optional,
            'charges' => $optional,
            'paper_total' => $optional,
            'bill' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ] + $this->rowRules(100, [
            'item' => ['bail', 'required', 'integer'],
            'quantity' => ['bail', 'required', ...$this->quantityRule()],
            'unit' => ['bail', 'required', 'string'],
            'amount' => ['bail', 'required', ...$this->moneyRule()],
        ]), ['method.*' => 'Choose cash, UPI or bank.', 'bill.max' => 'Choose a file of at most 5 MB.', 'bill.*' => 'Choose a JPG, PNG or PDF.'] + $this->messages());

        $money = fn (string $key) => isset($input[$key]) ? Rupees::parse($input[$key]) : null;
        $file = $request->file('bill')?->store('bills', 'local') ?: null;
        $saved = null;
        try {
            $saved = $this->orRefuse(fn () => $stock->save([
                'type' => 'purchase', 'date' => $input['date'], 'token' => $input['token'], 'note' => $input['note'] ?? null,
                'shop_id' => isset($input['shop_id']) ? (int) $input['shop_id'] : null, 'bill_number' => $input['bill_number'] ?? null,
                'different_bill' => (bool) ($input['different_bill'] ?? false), 'method' => $input['method'],
                'discount' => $money('discount') ?? 0, 'charges' => $money('charges') ?? 0, 'paper_total' => $money('paper_total'), 'bill_file' => $file,
            ], array_map(fn ($row) => ['amount' => (string) $row['amount']] + $row, $this->itemRows($input['rows'], ['amount']))));
        } finally {
            // Not saved, or a repeat of an earlier save.
            if ($file !== null && $saved?->bill_file !== $file) {
                Storage::disk('local')->delete($file);
            }
        }

        return redirect()->route('purchases.show', $saved->id)->with('success', 'Purchase of ' . Rupees::format($saved->total) . ' saved.');
    }

    public function show(string $entry)
    {
        $purchase = $this->purchase($entry)->load('cancellation', 'movements.item');

        return Inertia::render('Purchases/Show', [
            'purchase' => $this->row($purchase) + $purchase->only('note', 'discount', 'charges', 'user_name') + [
                'bill' => $purchase->bill_file !== null,
                'reason' => $purchase->cancellation?->reason_text,
                'cancelled_by' => $purchase->cancellation?->user_name,
            ],
            'rows' => $purchase->movements->map(fn ($row) => $row->only('id', 'entered', 'cost') + [
                'item_id' => $row->stock_item_id, 'name' => $row->item->name, 'unit' => $row->item->unit, 'rate' => StockItem::share($row->cost, 1000, $row->quantity),
            ]),
            'used' => $this->usedSince($purchase),
        ]);
    }

    // "4 kg of Rice": use and waste after this purchase, whose stored cost a cancellation does not change.
    private function usedSince(StockEntry $purchase): array
    {
        $items = $purchase->movements->keyBy('stock_item_id');
        $out = StockMovement::whereIn('stock_item_id', $items->keys())->where('id', '>', $purchase->movements->min('id'))
            ->whereHas('entry', fn ($q) => $q->whereIn('type', ['use', 'waste'])->whereDoesntHave('cancellation'))
            ->groupBy('stock_item_id')->selectRaw('stock_item_id, sum(quantity) as total')->pluck('total', 'stock_item_id');

        return $out->map(fn ($total, $id) => $items[$id]->item->amount(-(int) $total) . ' of ' . $items[$id]->item->name)->values()->all();
    }

    public function bill(string $entry)
    {
        $file = $this->purchase($entry)->bill_file;
        $disk = Storage::disk('local');
        abort_if($file === null || ! $disk->exists($file), 404);
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        return Str::endsWith($file, '.pdf') ? $disk->download($file, "bill_$entry.pdf", $headers) : $disk->response($file, null, $headers);
    }

    private function purchase(string $id): StockEntry
    {
        return StockEntry::where('type', 'purchase')->findOrFail($id);
    }

    private function row(StockEntry $entry): array
    {
        return $entry->only('id', 'date', 'shop_name', 'bill_number', 'total') + [
            'method' => StockEntry::METHODS[$entry->method], 'cancelled' => $entry->cancellation !== null,
        ];
    }
}

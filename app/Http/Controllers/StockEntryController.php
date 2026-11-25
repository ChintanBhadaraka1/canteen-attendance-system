<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Concerns\ReadsStockRows;
use App\Models\MenuOption;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StockEntryController extends Controller
{
    use ListsRecords, ReadsStockRows;

    public function useForm()
    {
        return $this->form('use');
    }

    public function wasteForm()
    {
        return $this->form('waste');
    }

    public function use(Request $request, StockService $stock)
    {
        return $this->saveOut('use', $request, $stock);
    }

    public function waste(Request $request, StockService $stock)
    {
        return $this->saveOut('waste', $request, $stock);
    }

    public function countForm(Request $request)
    {
        $category = MenuOption::of('stock_category')->find((int) $this->text($request, 'category'));

        return Inertia::render('Stock/Count', [
            'items' => $this->items()->filter(fn ($item) => ! $category || $item->category_id === $category->id)->map->choice()->values(),
            'category' => $category?->id,
            'categories' => MenuOption::of('stock_category')->get()->map->only('id', 'name'),
            'token' => (string) Str::uuid(),
        ]);
    }

    public function count(Request $request, StockService $stock)
    {
        $rows = $request->input('rows');
        // Only counted items are sent on; an empty box is not zero.
        if (is_array($rows)) {
            $request->merge(['rows' => array_filter($rows, fn ($row) => ! is_array($row) || ($row['quantity'] ?? '') !== '')]);
        }
        $input = $request->validate($this->entryRules() + ['reason_text' => ['nullable', 'string', 'min:3', 'max:200']] + $this->rowRules(1000, [
            'item' => ['bail', 'required', 'integer', 'distinct'],
            'quantity' => ['bail', 'required', ...$this->quantityRule(0)],
            'unit' => ['bail', 'required', 'string'],
            'expected' => ['bail', 'required', 'integer'],
        ]), ['rows.required' => 'Count at least one item.', 'rows.*.item.distinct' => 'Row :position: this item is already in the list.'] + $this->messages());

        $entry = $this->orRefuse(fn () => $stock->count(
            ['date' => $input['date'], 'token' => $input['token'], 'note' => $input['note'] ?? null, 'reason_text' => $input['reason_text'] ?? null],
            array_map(fn ($row) => ['expected' => (int) $row['expected']] + $row, $this->itemRows($input['rows'], ['expected']))
        ));

        return redirect()->route('stock.index')->with('success', $entry ? 'Stock count saved.' : 'Everything matches. Nothing to change.');
    }

    public function cancel(Request $request, StockService $stock, string $entry)
    {
        $row = StockEntry::findOrFail($entry);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $this->orRefuse(fn () => $stock->cancel($row->id, $input['reason']));

        $item = is_numeric($request->input('item')) ? StockItem::find((int) $request->input('item')) : null;
        $back = match (true) {
            $item !== null => redirect()->route('stock.show', $item->id),
            $row->type === 'purchase' => redirect()->route('purchases.show', $row->id),
            default => redirect()->route('stock.index'),
        };

        return $back->with('success', StockEntry::TYPES[$row->type] . ' cancelled.');
    }

    private function form(string $type)
    {
        return Inertia::render('Stock/Entry', [
            'type' => $type,
            'items' => $this->items()->map->choice()->values(),
            'reasons' => MenuOption::of('waste_reason')->where('active', true)->get()->map->only('id', 'name'),
            'token' => (string) Str::uuid(),
        ]);
    }

    private function saveOut(string $type, Request $request, StockService $stock)
    {
        $this->dropEmptyRows($request, ['item', 'quantity']);
        $input = $request->validate($this->entryRules() + $this->rowRules(100, [
            'item' => ['bail', 'required', 'integer'],
            'quantity' => ['bail', 'required', ...$this->quantityRule()],
            'unit' => ['bail', 'required', 'string'],
        ]) + ($type === 'waste' ? ['reason_id' => ['bail', 'required', 'integer'], 'reason_text' => ['nullable', 'string', 'max:200']] : []),
            ['reason_id.required' => 'Choose a reason.'] + $this->messages());

        $this->orRefuse(fn () => $stock->save([
            'type' => $type, 'date' => $input['date'], 'token' => $input['token'], 'note' => $input['note'] ?? null,
        ] + ($type === 'waste' ? ['reason_id' => (int) $input['reason_id'], 'reason_text' => $input['reason_text'] ?? null] : []), $this->itemRows($input['rows'])));

        return redirect()->route('stock.index')->with('success', $type === 'use' ? 'Kitchen use saved.' : 'Waste saved.');
    }

    // Items that are on, and those turned off that still have stock.
    private function items()
    {
        return StockItem::with('packs')->where(fn ($q) => $q->where('active', true)->orWhere('on_hand', '>', 0))->orderBy('name')->get();
    }
}

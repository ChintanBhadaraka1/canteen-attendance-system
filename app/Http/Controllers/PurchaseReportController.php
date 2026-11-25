<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\StockEntry;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Support\Csv;
use App\Support\Quantity;
use App\Support\Rupees;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class PurchaseReportController extends Controller
{
    use ListsRecords;

    public function index(Request $request)
    {
        return Inertia::render('Purchases/Report', $this->report($request));
    }

    public function download(Request $request)
    {
        $report = $this->report($request);
        $rows = [];
        foreach ($report['periods'] as $row) {
            $rows[] = ['Period', $row['label'], '', '', Rupees::show($row['total'])];
        }
        foreach ($report['shops'] as $row) {
            $rows[] = ['Shop', Csv::cell($row['name']), '', '', Rupees::show($row['total'])];
        }
        foreach ($report['items'] as $row) {
            $rows[] = ['Item', Csv::cell($row['name']), $row['unit'], Quantity::show($row['bought']), Rupees::show($row['bought_cost']),
                Quantity::show($row['used']), Rupees::show($row['used_cost']), Quantity::show($row['wasted']), Rupees::show($row['wasted_cost'])];
        }

        return Csv::download("purchases_{$report['filters']['from']}_{$report['filters']['to']}.csv",
            ['Part', 'Name', 'Unit', 'Bought', 'Bought amount', 'Used', 'Used amount', 'Wasted', 'Wasted amount'], $rows);
    }

    private function report(Request $request): array
    {
        [$from, $to, $message] = $this->reportDates($request);
        $by = $this->text($request, 'by') === 'month' ? 'month' : 'week';

        $purchases = StockEntry::notCancelled()->where('type', 'purchase')->whereBetween('date', [$from, $to])
            ->orderBy('date')->get(['id', 'date', 'shop_id', 'shop_name', 'discount', 'charges', 'total']);
        $movements = StockMovement::query()->join('stock_entries', 'stock_entries.id', '=', 'stock_movements.stock_entry_id')
            ->whereIn('stock_entries.type', ['purchase', 'use', 'waste'])->whereBetween('stock_entries.date', [$from, $to])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_entries as c')->whereColumn('c.cancels_id', 'stock_entries.id'))
            ->groupBy('stock_item_id', 'stock_entries.type')
            ->selectRaw('stock_item_id, stock_entries.type, sum(quantity) as quantity, sum(cost) as cost')->toBase()->get()->groupBy('stock_item_id');
        $items = StockItem::whereKey($movements->keys())->orderBy('name')->get()->map(function ($item) use ($movements) {
            $of = $movements[$item->id]->keyBy('type');
            $get = fn (string $type, string $part) => abs((int) ($of[$type]->$part ?? 0));

            return $item->only('id', 'name', 'unit') + [
                'bought' => $get('purchase', 'quantity'), 'bought_cost' => $get('purchase', 'cost'),
                'used' => $get('use', 'quantity'), 'used_cost' => $get('use', 'cost'),
                'wasted' => $get('waste', 'quantity'), 'wasted_cost' => $get('waste', 'cost'),
            ];
        });

        return [
            'filters' => compact('from', 'to', 'by'),
            'message' => $message,
            'periods' => $this->periods($purchases, $by, $from, $to),
            'shops' => $purchases->groupBy('shop_id')->map(fn ($rows) => ['name' => $rows->last()->shop_name ?? 'No shop', 'total' => $rows->sum('total')])
                ->sortByDesc('total')->values(),
            'items' => $items,
            'total' => $purchases->sum('total'),
            'discount' => $purchases->sum('discount'),
            'charges' => $purchases->sum('charges'),
        ];
    }

    private function periods(Collection $purchases, string $by, string $from, string $to): Collection
    {
        return $purchases->groupBy(function ($row) use ($by) {
            $date = Carbon::parse($row->date);

            return ($by === 'month' ? $date->startOfMonth() : $date->startOfWeek(Carbon::MONDAY))->toDateString();
        })->map(function ($rows, $start) use ($by, $from, $to) {
            $start = Carbon::parse($start);
            $end = $by === 'month' ? $start->copy()->endOfMonth() : $start->copy()->addDays(6);
            // A period cut by the range shows only its days inside it.
            $first = max($start->toDateString(), $from);
            $last = min($end->toDateString(), $to);
            $days = Carbon::parse($first)->format('j M Y') . ($first === $last ? '' : ' to ' . Carbon::parse($last)->format('j M Y'));
            $whole = $first === $start->toDateString() && $last === $end->toDateString();

            return [
                'label' => $by === 'month' ? $start->format('F Y') . ($whole ? '' : " ($days)") : $days,
                'total' => $rows->sum('total'), 'count' => $rows->count(),
            ];
        })->values();
    }
}

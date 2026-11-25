<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityController extends Controller
{
    use ListsRecords;

    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['id'], 'id', 'desc');
        $type = $this->text($request, 'type');
        $filters['type'] = in_array($type, ['attendance', 'guest', 'payment', 'student', 'meal', 'menu', 'stock', 'expense', 'staff', 'cash', 'settings', 'option'], true) ? $type : '';
        $filters += $this->optionalDates($request);

        $query = ActivityLog::orderBy('id', $filters['dir']);
        $this->searchIn($query, ['summary', 'user_name'], $filters['search']);
        if ($filters['type'] !== '') {
            $also = ['meal' => 'special_price', 'menu' => 'menu_plan', 'stock' => 'stock_item', 'settings' => 'option'][$filters['type']] ?? $filters['type'];
            $query->where(fn ($q) => $q->where('action', 'like', $filters['type'] . '.%')->orWhere('action', 'like', $also . '.%'));
        }
        if ($filters['from'] !== '') {
            $query->where('created_at', '>=', $filters['from'] . ' 00:00:00');
        }
        if ($filters['to'] !== '') {
            $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }

        return Inertia::render('Activity/Index', [
            'changes' => $this->pageOf($query)->through(fn ($row) => $row->only('id', 'user_name', 'summary') + [
                'when' => $row->created_at->format('j M Y, g:i a'),
            ]),
            'filters' => $filters,
        ]);
    }
}

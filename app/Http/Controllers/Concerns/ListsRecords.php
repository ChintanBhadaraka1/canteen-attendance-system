<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

trait ListsRecords
{
    protected function listParams(Request $request, array $sortable, string $default, string $defaultDir = 'asc'): array
    {
        $sort = $request->input('sort');
        $dir = $request->input('dir');

        return [
            'search' => mb_substr(trim($this->text($request, 'search')), 0, 100),
            'sort' => in_array($sort, $sortable, true) ? $sort : $default,
            'dir' => in_array($dir, ['asc', 'desc'], true) ? $dir : $defaultDir,
        ];
    }

    // Dates from the address bar; anything invalid falls back to the last 30 days.
    protected function dates(Request $request): array
    {
        $valid = Validator::make($request->only('from', 'to'), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ])->passes();

        return $valid ? $request->only('from', 'to') : [
            'from' => today()->subDays(29)->toDateString(),
            'to' => today()->toDateString(),
        ];
    }

    // Each date on its own; anything invalid is empty.
    protected function optionalDates(Request $request): array
    {
        $filters = [];
        foreach (['from', 'to'] as $key) {
            $valid = Validator::make($request->only($key), [$key => ['required', 'date_format:Y-m-d']])->passes();
            $filters[$key] = $valid ? $request->input($key) : '';
        }

        return $filters;
    }

    // A report's dates: this month unless valid, at most 366 days. The third part is a message to show.
    protected function reportDates(Request $request): array
    {
        $month = [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()];
        if (! $request->hasAny('from', 'to')) {
            return [...$month, null];
        }
        ['from' => $from, 'to' => $to] = $this->optionalDates($request);
        if ($from === '' || $to === '' || $from > $to) {
            return [...$month, 'These dates could not be used, so this month is shown.'];
        }

        return Carbon::parse($from)->diffInDays($to) >= 366 ? [...$month, 'Choose at most 366 days. This month is shown.'] : [$from, $to, null];
    }

    protected function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    // "!" escapes because MariaDB and SQLite disagree about backslashes.
    protected function searchIn($query, array $columns, string $search)
    {
        if ($search === '') {
            return $query;
        }

        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        return $query->where(function ($q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhereRaw("$column like ? escape '!'", [$like]);
            }
        });
    }

    protected function pageOf($query): LengthAwarePaginator
    {
        $rows = $query->paginate(10);

        return $rows->currentPage() > $rows->lastPage()
            ? $query->paginate(10, page: $rows->lastPage())
            : $rows;
    }
}

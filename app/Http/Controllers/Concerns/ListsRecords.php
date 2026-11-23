<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

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

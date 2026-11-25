<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

trait MovesRows
{
    /**
     * Swaps a row with its neighbour. Call with the rows locked, in shown order.
     */
    protected function moveRow(Collection $rows, int $id, string $direction): ?Model
    {
        $rows = $rows->values();
        $at = $rows->search(fn ($row) => $row->id === $id);
        $to = $at + ($direction === 'up' ? -1 : 1);
        if ($at === false || ! $rows->has($to)) {
            return null;
        }

        $moved = $rows[$at];
        $rows[$at] = $rows[$to];
        $rows[$to] = $moved;
        $rows->each(fn ($row, $position) => $row->update(['position' => $position + 1]));

        return $moved;
    }
}

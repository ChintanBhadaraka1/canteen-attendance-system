<?php

namespace App\Models\Concerns;

use App\Exceptions\RecordException;

// Records that are cancelled with a reason, never edited or deleted.
trait Cancellable
{
    public function scopeNotCancelled($query)
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * The row, locked. Call it first in a transaction, or once its owner is locked.
     */
    public static function lockedToCancel(int $id): static
    {
        $row = static::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($row->cancelled_at !== null) {
            throw new RecordException('This was already cancelled.', 'reason');
        }

        return $row;
    }

    public function cancelWith(string $reason): void
    {
        $this->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
    }

    /**
     * The row already saved by this user with this token.
     */
    public static function savedWith(string $token, string $field, array $same = []): ?static
    {
        $row = static::where('token', $token)->first();
        if ($row !== null && ($row->created_by !== auth()->id() || array_diff_assoc($same, $row->getAttributes()))) {
            throw new RecordException('This was already saved. Reload the page and try again.', $field);
        }

        return $row;
    }

    public static function author(): array
    {
        return ['created_by' => auth()->id(), 'user_name' => auth()->user()->full_name ?? 'System'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['details' => 'array'];

    /**
     * Call inside the transaction that makes the change. Never pass emails, phones or passwords.
     */
    public static function record(string $action, ?int $studentId, string $summary, array $details = []): void
    {
        $user = auth()->user();

        static::create([
            'user_id' => $user?->id,
            'user_name' => $user->full_name ?? 'System',
            'action' => $action,
            'student_id' => $studentId,
            'summary' => Str::limit($summary, 254, '…'),
            'details' => $details ?: null,
        ]);
    }

    /**
     * Describe unsaved changes by field name; only a price shows its values. Empty when nothing changed.
     */
    public static function describe(Model $model, array $labels): string
    {
        $name = $model->getOriginal('full_name') ?? $model->getOriginal('name');
        $changed = array_intersect_key($labels, $model->getDirty());
        $parts = [];

        if (isset($changed['price']) && $model->price !== null && $model->getOriginal('price') !== null) {
            $parts[] = "price changed from ₹{$model->getOriginal('price')} to ₹{$model->price}";
            unset($changed['price']);
        }
        if ($changed) {
            $parts[] = 'changed: ' . implode(', ', array_unique($changed));
        }

        return $parts ? "$name " . implode('; ', $parts) : '';
    }
}

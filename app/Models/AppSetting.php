<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    // Parts of the app that can be turned off => [shown name, on when nothing is saved]
    const MODULES = ['expenses' => ['Expenses', true], 'staff' => ['Staff', false], 'cash' => ['Cash check', false]];

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, bool> read once per request
     */
    public static function modules(): array
    {
        $kept = request()->attributes;
        if (! $kept->has('modules')) {
            $saved = static::where('key', 'like', 'module_%')->pluck('value', 'key');
            $kept->set('modules', collect(self::MODULES)
                ->map(fn ($module, $name) => isset($saved["module_$name"]) ? $saved["module_$name"] === '1' : $module[1])->all());
        }

        return $kept->get('modules');
    }

    public static function isOn(string $module): bool
    {
        return static::modules()[$module];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MovesRows;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\MenuOption;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SettingsController extends Controller
{
    use MovesRows;

    const TAKEN = 'This name is already in the list.';

    public function index()
    {
        return Inertia::render('Settings/Index', [
            'kinds' => collect(MenuOption::KINDS)->keys()->mapWithKeys(fn ($kind) => [
                $kind => MenuOption::of($kind)->get()->map(fn ($option) => $option->only('id', 'name', 'active') + ['used' => $option->usedBy()]),
            ]),
        ]);
    }

    public function modules(Request $request)
    {
        $input = $request->validate([
            'module' => ['bail', 'required', 'string', 'in:' . implode(',', array_keys(AppSetting::MODULES))],
            'on' => ['required', 'boolean'],
        ]);
        $on = (bool) $input['on'];
        $name = AppSetting::MODULES[$input['module']][0];

        DB::transaction(function () use ($input, $on, $name) {
            $row = AppSetting::whereKey("module_{$input['module']}")->lockForUpdate()->first();
            if (($row ? $row->value === '1' : AppSetting::MODULES[$input['module']][1]) === $on) {
                return;
            }
            AppSetting::updateOrCreate(['key' => "module_{$input['module']}"], ['value' => $on ? '1' : '0']);
            ActivityLog::record('settings.changed', null, "$name turned " . ($on ? 'on' : 'off'));
        }, 3);

        return $this->done("$name turned " . ($on ? 'on' : 'off') . '.');
    }

    public function store(Request $request)
    {
        $input = $request->validate([
            'kind' => ['bail', 'required', 'string', 'in:' . implode(',', array_keys(MenuOption::KINDS))],
            'name' => $this->nameRules(is_string($request->input('kind')) ? $request->input('kind') : ''),
        ]);

        $option = $this->saved(fn () => DB::transaction(function () use ($input) {
            $option = MenuOption::create($input + ['position' => MenuOption::where('kind', $input['kind'])->max('position') + 1]);
            ActivityLog::record('option.added', null, "{$option->name} added to {$this->label($option)}");

            return $option;
        }));

        return $this->done("{$option->name} added.");
    }

    public function update(Request $request, string $option)
    {
        $row = MenuOption::findOrFail($option);
        $input = $request->validate([
            'name' => ['sometimes', ...$this->nameRules($row->kind, $row->id)],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        $error = $this->saved(fn () => DB::transaction(function () use ($row, $input) {
            // All of the kind, so two cannot be turned off at once.
            $rows = MenuOption::where('kind', $row->kind)->orderBy('id')->lockForUpdate()->get();
            $option = $rows->find($row->id) ?? abort(404);
            $name = $option->name;
            $option->fill($input);
            if ($option->isDirty('active') && ! $option->active && $this->isLastType($rows, $option)) {
                return "$name is the only food type that is on, so it cannot be turned off.";
            }
            $changes = array_keys($option->getDirty());
            if ($changes) {
                $option->save();
                $parts = array_filter([
                    in_array('name', $changes) ? "renamed to {$option->name}" : null,
                    in_array('active', $changes) ? ($option->active ? 'turned on' : 'turned off') : null,
                ]);
                ActivityLog::record('option.changed', null, "$name ({$this->label($option)}) " . implode(', ', $parts));
            }

            return null;
        }, 3));

        return $error ? back()->with('error', $error) : $this->done('Change saved.');
    }

    public function destroy(string $option)
    {
        $kind = MenuOption::findOrFail($option)->kind;

        $error = DB::transaction(function () use ($option, $kind) {
            // First query on purpose: saving a menu item takes a shared lock on its options.
            $rows = MenuOption::where('kind', $kind)->orderBy('id')->lockForUpdate()->get();
            $row = $rows->find($option) ?? abort(404);
            if ($used = $row->usedBy()) {
                return "{$row->name} is used by $used " . Str::plural(MenuOption::KINDS[$row->kind][1], $used) . '. Turn it off instead.';
            }
            if ($this->isLastType($rows, $row)) {
                return "{$row->name} is the only food type that is on, so it cannot be deleted.";
            }
            $row->delete();
            ActivityLog::record('option.deleted', null, "{$row->name} deleted from {$this->label($row)}");

            return null;
        }, 3);

        return $error ? back()->with('error', $error) : $this->done('Deleted.');
    }

    public function move(Request $request, string $option)
    {
        $input = $request->validate(['direction' => ['required', 'in:up,down']]);
        $row = MenuOption::findOrFail($option);

        DB::transaction(function () use ($row, $input) {
            $moved = $this->moveRow(MenuOption::of($row->kind)->lockForUpdate()->get(), $row->id, $input['direction']);
            if ($moved) {
                ActivityLog::record('option.changed', null, "{$moved->name} ({$this->label($moved)}) moved {$input['direction']}");
            }
        }, 3);

        return $this->done(null);
    }

    private function nameRules(string $kind, ?int $except = null): array
    {
        return ['bail', 'required', 'string', 'min:2', 'max:30', function ($field, $name, $fail) use ($kind, $except) {
            $names = MenuOption::where('kind', $kind)->when($except, fn ($q) => $q->whereKeyNot($except))->pluck('name');
            if ($names->contains(fn ($other) => mb_strtolower($other) === mb_strtolower($name))) {
                $fail(self::TAKEN);
            }
        }];
    }

    private function saved(callable $save): mixed
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::TAKEN]);
        }
    }

    private function isLastType($rows, MenuOption $option): bool
    {
        return $option->kind === 'food_type' && $rows->where('active', true)->where('id', '!=', $option->id)->isEmpty();
    }

    private function label(MenuOption $option): string
    {
        return MenuOption::KINDS[$option->kind][0];
    }

    private function done(?string $message)
    {
        $redirect = redirect()->route('settings.index');

        return $message === null ? $redirect : $redirect->with('success', $message);
    }
}

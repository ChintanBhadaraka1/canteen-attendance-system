<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\RecordException;
use App\Support\Quantity;
use App\Support\Rupees;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ReadsStockRows
{
    /**
     * Drops rows left empty. The keys stay, so errors name the row on the screen.
     */
    protected function dropEmptyRows(Request $request, array $fields): void
    {
        $rows = $request->input('rows');
        if (is_array($rows)) {
            $request->merge(['rows' => array_filter($rows, fn ($row) => ! is_array($row)
                || array_filter(array_intersect_key($row, array_flip($fields)), fn ($value) => $value !== null && $value !== ''))]);
        }
    }

    protected function rowRules(int $most, array $fields): array
    {
        return ['rows' => ['bail', 'required', 'array', "max:$most", function ($field, $rows, $fail) {
            if (array_filter(array_keys($rows), fn ($key) => ! is_int($key))) {
                $fail('This could not be read.');
            }
        }], 'rows.*' => ['bail', 'array']] + array_combine(array_map(fn ($name) => "rows.*.$name", array_keys($fields)), $fields);
    }

    protected function entryRules(): array
    {
        return [
            'date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:' . today()->subDays(366)->toDateString()],
            'token' => ['bail', 'required', 'string', 'uuid'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function quantityRule(int $least = 1): array
    {
        return [$this->scalar(), fn ($field, $value, $fail) => (Quantity::parse($value) ?? -1) >= $least ?: $fail($this->rowName($field) . 'Enter a quantity, for example 2.5.')];
    }

    protected function moneyRule(): array
    {
        return [$this->scalar(), fn ($field, $value, $fail) => Rupees::parse($value) !== null ?: $fail($this->rowName($field) . 'Enter an amount, for example 42.50.')];
    }

    // More than nothing, up to a crore.
    protected function amountRule(): array
    {
        return [...$this->moneyRule(), fn ($field, $value, $fail) => (($paise = Rupees::parse($value)) > 0 && $paise <= 1_000_000_000) ?: $fail('Enter an amount above 0, up to ₹1,00,00,000.')];
    }

    protected function messages(): array
    {
        return [
            'rows.required' => 'Fill in at least one row.',
            'rows.max' => 'At most :max rows.',
            'rows.*.*.required' => 'Row :position: fill in this box.',
            'rows.*.item.*' => 'Row :position: choose an item.',
            'rows.*.unit.*' => 'Row :position: choose a unit.',
            'date.after_or_equal' => 'Choose a date in the last year.',
            'date.before_or_equal' => 'Choose today or an earlier date.',
        ];
    }

    private function scalar(): Closure
    {
        return fn ($field, $value, $fail) => is_string($value) || is_int($value) ?: $fail($this->rowName($field) . 'This could not be read.');
    }

    private function rowName(string $field): string
    {
        return preg_match('/^rows\.(\d+)\./', $field, $parts) ? 'Row ' . ($parts[1] + 1) . ': ' : '';
    }

    protected function itemRows(array $rows, array $more = []): array
    {
        return array_map(fn ($row) => ['item' => (int) $row['item'], 'quantity' => (string) $row['quantity'], 'unit' => $row['unit']]
            + array_intersect_key($row, array_flip($more)), $rows);
    }

    protected function orRefuse(Closure $work): mixed
    {
        try {
            return $work();
        } catch (RecordException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }
}

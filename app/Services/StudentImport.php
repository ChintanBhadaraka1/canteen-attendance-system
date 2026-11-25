<?php

namespace App\Services;

use App\Exceptions\StudentImportException;
use App\Http\Requests\StudentRules;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class StudentImport
{
    const MAX_ROWS = 500;

    const COLUMNS = [
        'first_name' => 'name', 'middle_name' => 'middle_name', 'last_name' => 'last_name', 'college' => 'collage_name',
        'email' => 'email', 'phone' => 'phone_number', 'canteen_id' => 'canteen_id',
    ];

    const OPTIONAL = ['middle_name', 'last_name'];

    /**
     * @return array<int, array{line: int, data: array, errors: string[], warnings: string[]}>
     */
    public static function read(string $path): array
    {
        $rows = self::parse($path);
        $rules = array_map(
            fn ($rules) => array_filter($rules, fn ($rule) => ! $rule instanceof Unique),
            StudentRules::rules()
        );
        $labels = ['email' => 'email', 'canteen_id' => 'canteen ID'];
        $used = ['email' => [], 'canteen_id' => []];
        // One query. Deleted students keep their email and canteen ID.
        foreach (User::withTrashed()->toBase()->get(['email', 'canteen_id']) as $user) {
            $used['email'][mb_strtolower($user->email ?? '')] = true;
            $used['canteen_id'][mb_strtolower(trim($user->canteen_id ?? ''))] = true;
        }
        $seen = ['email' => [], 'canteen_id' => []];

        foreach ($rows as &$row) {
            $validator = Validator::make($row['data'], $rules, StudentRules::messages(), StudentRules::attributes());
            $errors = $validator->errors();
            if (preg_match('/^\d+([.,]\d+)?E\+?\d+$/i', $row['data']['phone_number'] ?? '')) {
                $errors->forget('phone_number')->add('phone_number', 'Excel changed this phone number. Format the column as Text and save again.');
            }
            foreach ($labels as $field => $label) {
                $value = mb_strtolower($row['data'][$field] ?? '');
                if ($value === '') {
                    continue;
                }
                if (isset($used[$field][$value])) {
                    $errors->add($field, "This $label is already used.");
                } elseif (isset($seen[$field][$value])) {
                    $errors->add($field, "This $label is already in line {$seen[$field][$value]} of the file.");
                }
                $seen[$field][$value] ??= $row['line'];
            }
            $row['errors'] = array_merge($row['errors'], $errors->all());
        }
        unset($row);

        return self::warnAboutZeros($rows);
    }

    /**
     * Saves the rows without errors, all or none.
     */
    public static function save(array $rows): int
    {
        $rows = array_filter($rows, fn ($row) => ! $row['errors']);

        return DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                (new User)->fill($row['data'])->forceFill(['role' => 'user'])->save();
            }
            $count = count($rows);
            ActivityLog::record('student.imported', null, $count . ' ' . Str::plural('student', $count) . ' added from a file');

            return $count;
        });
    }

    private static function parse(string $path): array
    {
        $text = is_file($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            throw new StudentImportException('Choose the file again.');
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new StudentImportException('This file uses an old text format. In Excel, choose Save as, CSV UTF-8.');
        }
        $text = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\x{FEFF}/u', '', $text));

        $first = strtok($text, "\n") ?: '';
        $separators = array_map(fn ($separator) => substr_count($first, $separator), [',' => ',', ';' => ';', "\t" => "\t"]);
        $separator = array_search(max($separators), $separators, true);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);
        $header = self::header(fgetcsv($handle, null, $separator, '"', '') ?: []);

        $rows = [];
        for ($line = 2; ($cells = fgetcsv($handle, null, $separator, '"', '')) !== false; $line++) {
            $cells = array_map(fn ($cell) => Str::trim($cell ?? ''), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            if (count($rows) === self::MAX_ROWS) {
                throw new StudentImportException('Please add at most ' . self::MAX_ROWS . ' students at a time.');
            }
            $extra = implode('', array_slice($cells, count($header))) !== '';
            $data = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $rows[] = [
                'line' => $line,
                'data' => array_map(fn ($cell) => $cell === '' ? null : $cell, $data),
                'errors' => $extra ? ['This row has too many cells. Put text with a comma inside quotes.'] : [],
                'warnings' => [],
            ];
        }
        fclose($handle);

        return $rows ?: throw new StudentImportException('There are no students in this file.');
    }

    // The database field of each column, in file order.
    private static function header(array $cells): array
    {
        $names = array_map(fn ($cell) => Str::lower(Str::trim($cell ?? '')), $cells);
        // Empty columns at the end are left by spreadsheets.
        while ($names && end($names) === '') {
            array_pop($names);
        }
        $problems = array_filter([
            'Missing columns' => array_diff(array_keys(self::COLUMNS), self::OPTIONAL, $names),
            'Unknown columns' => array_diff($names, array_keys(self::COLUMNS)),
            'Repeated columns' => array_unique(array_diff_assoc($names, array_unique($names))),
        ]);
        if ($problems) {
            throw new StudentImportException(implode('. ', array_map(
                fn ($label, $columns) => $label . ': ' . Str::limit(implode(', ', $columns), 100), array_keys($problems), $problems
            )) . '. Use the template.');
        }

        return array_map(fn ($name) => self::COLUMNS[$name], $names);
    }

    private static function warnAboutZeros(array $rows): array
    {
        $ids = array_map(fn ($row) => $row['data']['canteen_id'] ?? '', $rows);
        $longest = max(array_map(strlen(...), $ids));

        if (! array_filter($ids, fn ($id) => ! ctype_digit($id))) {
            foreach ($rows as $n => $row) {
                if (strlen($ids[$n]) < $longest) {
                    $rows[$n]['warnings'][] = 'Excel may have removed zeros from the front of IDs. Please check.';
                }
            }
        }

        return $rows;
    }
}

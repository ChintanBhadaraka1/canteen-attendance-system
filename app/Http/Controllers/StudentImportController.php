<?php

namespace App\Http\Controllers;

use App\Exceptions\StudentImportException;
use App\Services\StudentImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class StudentImportController extends Controller
{
    const CHANGED = 'The list has changed. Please check it again.';

    public function index(Request $request)
    {
        $rows = $this->rows($request);

        return Inertia::render('Students/Import', [
            'preview' => $rows === null ? null : [
                'good' => $this->good($rows),
                'bad' => count($rows) - $this->good($rows),
                // Wrong rows first; the order inside each group stays.
                'rows' => collect($rows)->sortBy(fn ($row) => $row['errors'] ? 0 : 1)->values(),
            ],
        ]);
    }

    public function preview(Request $request)
    {
        $request->validate(
            ['file' => ['bail', 'required', 'file', 'extensions:csv,txt', 'max:1024']],
            ['file.extensions' => 'Choose a .csv file.', 'file.max' => 'Choose a file of at most 1 MB.', 'file.required' => 'Choose a file.']
        );
        $disk = Storage::disk('local');
        $this->forget($request);
        foreach ($disk->files('imports') as $old) {
            if ($disk->lastModified($old) < now()->subDay()->timestamp) {
                $disk->delete($old);
            }
        }

        $name = 'imports/' . Str::random(40) . '.csv';
        $disk->put($name, $request->file('file')->get());
        try {
            StudentImport::read($disk->path($name));
        } catch (StudentImportException $e) {
            $disk->delete($name);

            return redirect()->route('user.import')->withErrors(['file' => $e->getMessage()]);
        }
        $request->session()->put('student_import', $name);

        return redirect()->route('user.import');
    }

    public function confirm(Request $request)
    {
        $request->validate(['count' => ['required', 'integer']]);
        $rows = $this->rows($request);
        if ($rows === null) {
            return redirect()->route('user.import')->with('error', 'Choose a file first.');
        }
        if ($this->good($rows) === 0 || $this->good($rows) !== $request->integer('count')) {
            return redirect()->route('user.import')->with('error', self::CHANGED);
        }

        try {
            $count = StudentImport::save($rows);
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('user.import')->with('error', self::CHANGED);
        }
        $this->forget($request);

        return redirect()->route('user.index')->with('success', $count . ' ' . Str::plural('student', $count) . ' added.');
    }

    public function discard(Request $request)
    {
        $this->forget($request);

        return redirect()->route('user.import');
    }

    public function template()
    {
        return response()->streamDownload(function () {
            // The mark makes Excel read and save the file as UTF-8.
            echo "\xEF\xBB\xBF" . implode(',', array_keys(StudentImport::COLUMNS)) . "\r\n";
            echo "Asha,Raj,Patel,North College,asha@example.com,9876543210,C101\r\n";
        }, 'students-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // The uploaded file of this session, read and checked again each time.
    private function rows(Request $request): ?array
    {
        $name = $request->session()->get('student_import');
        try {
            return $name === null ? null : StudentImport::read(Storage::disk('local')->path($name));
        } catch (StudentImportException) {
            return null; // Removed meanwhile, for example by a second confirm.
        }
    }

    private function good(array $rows): int
    {
        return count(array_filter($rows, fn ($row) => ! $row['errors']));
    }

    private function forget(Request $request): void
    {
        if ($name = $request->session()->pull('student_import')) {
            Storage::disk('local')->delete($name);
        }
    }
}

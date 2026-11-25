<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Models\User;
use App\Services\StudentStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class StatementController extends Controller
{
    use ListsRecords;

    const MOST = 500;

    public function one(Request $request, StudentStatement $statements, string $student)
    {
        $id = User::withTrashed()->where('role', 'user')->findOrFail($student)->id;
        $bill = $statements->for([$id], $this->month($request))[0];

        return $this->pdf([$bill])->download('bill_' . $this->safe($bill['canteen_id'] ?? "student-$id") . "_{$bill['month']}.pdf");
    }

    public function index(Request $request, StudentStatement $statements)
    {
        $valid = Validator::make($request->only('month'), $this->rules())->passes();
        $month = $valid ? $request->input('month') : today()->format('Y-m');
        $colleges = $this->colleges();
        [$college, $owing] = $this->filters($request, $colleges);
        $bills = collect($statements->all($month, $college, $owing));

        return Inertia::render('Reports/Statements', [
            'filters' => compact('month', 'college', 'owing'),
            'message' => $request->has('month') && ! $valid ? 'This month could not be used, so this month is shown.' : null,
            'colleges' => $colleges,
            'bills' => $bills->map(fn ($bill) => collect($bill)->only('id', 'name', 'canteen_id', 'college', 'available', 'closing')),
            'unprintable' => $bills->reject(fn ($bill) => StudentStatement::printable($bill['name']))->map(fn ($bill) => collect($bill)->only('name', 'canteen_id'))->values(),
            'most' => self::MOST,
        ]);
    }

    public function download(Request $request, StudentStatement $statements)
    {
        $month = $this->month($request);
        $bills = $statements->all($month, ...$this->filters($request, $this->colleges()));
        $problem = match (true) {
            ! $bills => 'No bills for this choice.',
            count($bills) > self::MOST => count($bills) . ' bills are too many for one file. Choose a college.',
            default => null,
        };
        if ($problem) {
            return redirect()->route('reports.statements', compact('month'))->with('error', $problem);
        }

        return $this->pdf($bills)->download("bills_$month.pdf");
    }

    private function pdf(array $bills)
    {
        // 300 bills were measured at 14 s and 260 MB.
        ini_set('memory_limit', '1G');
        set_time_limit(180);

        return Pdf::loadView('Bill.statement', compact('bills'))->setPaper('a4');
    }

    private function rules(): array
    {
        return ['month' => ['bail', 'required', 'string', 'date_format:Y-m', 'before_or_equal:' . today()->format('Y-m')]];
    }

    private function month(Request $request): string
    {
        return $request->validate($this->rules(), ['month.*' => 'Choose a month, this one or earlier.'])['month'];
    }

    private function filters(Request $request, array $colleges): array
    {
        $college = $this->text($request, 'college');

        return [in_array($college, $colleges, true) ? $college : '', $this->text($request, 'owing') === '1'];
    }

    private function colleges(): array
    {
        return User::withTrashed()->where('role', 'user')->whereNotNull('collage_name')->where('collage_name', '!=', '')->distinct()->orderBy('collage_name')->pluck('collage_name')->all();
    }

    private function safe(string $id): string
    {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $id), '-') ?: 'student';
    }
}

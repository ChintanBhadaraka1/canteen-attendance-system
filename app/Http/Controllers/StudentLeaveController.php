<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\StudentLeave;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentLeaveController extends Controller
{
    public function store(Request $request, string $user)
    {
        $student = User::where('role', 'user')->findOrFail($user);
        $input = $request->validate([
            'from_date' => ['bail', 'required', 'date_format:Y-m-d'],
            'to_date' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'reason' => ['nullable', 'string', 'max:100'],
        ], ['to_date.after_or_equal' => 'Choose the same day or a later one.']);

        if (Carbon::parse($input['from_date'])->diffInDays($input['to_date']) >= 366) {
            return back()->withErrors(['to_date' => 'Leave can be at most 366 days.']);
        }

        $saved = DB::transaction(function () use ($student, $input) {
            // Locked so two saves cannot overlap.
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $overlap = StudentLeave::where('student_id', $student->id)
                ->where('from_date', '<=', $input['to_date'])->where('to_date', '>=', $input['from_date'])->exists();
            if ($overlap) {
                return false;
            }
            StudentLeave::create(['student_id' => $student->id] + $input);
            ActivityLog::record('student.leave_added', $student->id,
                "{$student->full_name} away from {$this->day($input['from_date'])} to {$this->day($input['to_date'])}");

            return true;
        });

        return $saved
            ? redirect()->route('user.show', $student->id)->with('success', 'Leave saved.')
            : back()->withErrors(['from_date' => 'There is already leave in these dates.']);
    }

    public function destroy(string $leave)
    {
        $leave = StudentLeave::with('student')->findOrFail($leave);

        DB::transaction(function () use ($leave) {
            $leave->delete();
            ActivityLog::record('student.leave_deleted', $leave->student_id,
                "Leave of {$leave->student->full_name} from {$this->day($leave->from_date)} to {$this->day($leave->to_date)} deleted");
        });

        return redirect()->route('user.show', $leave->student_id)->with('success', 'Leave deleted.');
    }

    private function day(string $date): string
    {
        return Carbon::parse($date)->format('j F Y');
    }
}

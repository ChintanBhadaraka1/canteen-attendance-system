<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRules;
use App\Models\ActivityLog;
use App\Models\GuestMeal;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class UserController extends Controller
{
    use ListsRecords;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name', 'canteen_id', 'collage_name'], 'name');

        $colleges = User::where('role', 'user')->whereNotNull('collage_name')->distinct()->orderBy('collage_name')->pluck('collage_name');
        $college = $this->text($request, 'college');
        $filters['college'] = $colleges->contains($college) ? $college : '';
        $filters['status'] = $this->text($request, 'status') === 'left' ? 'left' : '';

        $query = User::where('role', 'user')->whereNull('left_on', not: $filters['status'] === 'left')->orderBy($filters['sort'], $filters['dir'])->orderBy('id');
        if ($filters['college'] !== '') {
            $query->where('collage_name', $filters['college']);
        }
        $this->searchIn($query, ['name', 'middle_name', 'last_name', 'email', 'canteen_id', 'collage_name'], $filters['search']);

        return Inertia::render('Students/Index', [
            'students' => $this->pageOf($query)->through(fn ($user) => $user->only(
                'id', 'name', 'middle_name', 'last_name', 'email', 'canteen_id', 'collage_name', 'profile_pic'
            )),
            'filters' => $filters,
            'colleges' => $colleges,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Students/Form', ['student' => null]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), StudentRules::rules(), StudentRules::messages(), StudentRules::attributes());
        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)      // Pass validation errors to the session
                ->withInput($request->except('password'));  // Repopulate old input (except password)
        }
        $input = $validator->validated();
        $studentName = $request->input('name');
        $phoneNumber = $request->input('phone_number');

        // Convert name to lowercase and replace spaces with underscores
        $formattedName = strtolower(str_replace(' ', '_', $studentName));
        $fileName = null;

        if ($request->hasFile('profile_pic')) {
            $file = $request->file('profile_pic');
            $extension = $file->getClientOriginalExtension();
            $fileName = $formattedName . '_' . $phoneNumber . '.' . $extension;

            // Define destination path inside public folder
            $destinationPath = public_path('image/profile');

            // Ensure directory exists
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            // Move the file to public/image/profile with new name
            $file->move($destinationPath, $fileName);

            // $fileName can now be stored in DB
        }
        $input['profile_pic'] =  $fileName;

        $user = DB::transaction(function () use ($input) {
            $user = (new User)->fill($input)->forceFill(['role' => 'user']);
            $user->save();
            ActivityLog::record('student.added', $user->id, "{$user->full_name} added");

            return $user;
        });

        return redirect()->route('user.index')->with('success', "{$user->full_name} added.");
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $startMonthDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $todayDate = Carbon::now()->format('Y-m-d');

        $userData  = User::findOrFail($id);

        $mealNames = MealPrice::select('id', 'name')->get();

        $studentAttendanceCount = StudentAttendance::select('meal_id', DB::raw('count(*) as total'))
            ->whereBetween('date', [$startMonthDate, $todayDate])
            ->where('student_id', $id)
            ->groupBy('meal_id')
            ->get();


        // Convert $studentAttendanceCount to a keyed collection for quick lookups by meal_id
        $attendanceByMealId = $studentAttendanceCount->keyBy('meal_id');

        // Map through mealNames and combine with attendance counts
        $combined = $mealNames->map(function ($meal) use ($attendanceByMealId) {
            return [
                'id' => $meal->id,
                'name' => $meal->name,
                'total' => $attendanceByMealId->has($meal->id) ? $attendanceByMealId->get($meal->id)->total : 0,
            ];
        });

        $bill = Payment::where('student_id', $id)->first();

        return Inertia::render('Students/Show', [
            'student' => $userData->only(
                'id', 'name', 'middle_name', 'last_name', 'email', 'phone_number', 'canteen_id', 'collage_name', 'profile_pic', 'left_on'
            ),
            'leaves' => $userData->leaves()->latest('from_date')->get(['id', 'from_date', 'to_date', 'reason']),
            'today' => $todayDate,
            'meals' => $combined,
            'guests' => (int) GuestMeal::notCancelled()->where('student_id', $id)->whereBetween('date', [$startMonthDate, $todayDate])->sum('plates'),
            'bill' => $bill?->only('amount', 'pending_amount', 'advance_amount'),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        return Inertia::render('Students/Form', [
            'student' => User::findOrFail($id)->only(
                'id', 'name', 'middle_name', 'last_name', 'email', 'phone_number', 'canteen_id', 'collage_name', 'profile_pic'
            ),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        // Find the user or fail with 404
        $user = User::findOrFail($id);
        $validator = Validator::make($request->all(), StudentRules::rules($user->id), StudentRules::messages(), StudentRules::attributes());

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput($request->except('password'));
        }

        $input = $validator->validated();

        $studentName = $request->input('name');
        $phoneNumber = $request->input('phone_number');

        $formattedName = strtolower(str_replace(' ', '_', $studentName));

        if ($request->hasFile('profile_pic')) {
            $file = $request->file('profile_pic');
            $extension = $file->getClientOriginalExtension();
            $fileName = $formattedName . '_' . $phoneNumber . '.' . $extension;

            // Path where to save the file (public/image/profile)
            $destinationPath = public_path('image/profile');

            // Move uploaded file to public/image/profile
            $file->move($destinationPath, $fileName);

            $input['profile_pic'] = $fileName;
        } else {
            // Keep old profile_pic if no new file uploaded
            $input['profile_pic'] = $user->profile_pic;
        }

        $oldPhoto = $user->profile_pic;
        $name = $user->full_name;
        DB::transaction(function () use ($user, $input, $name, $request) {
            $user->fill($input);
            $labels = ['name' => 'first name', 'middle_name' => 'middle name', 'last_name' => 'last name', 'collage_name' => 'college',
                'email' => 'email', 'canteen_id' => 'canteen ID', 'phone_number' => 'phone number', 'profile_pic' => 'photo'];
            $changed = array_intersect_key($labels, $user->getDirty());
            if ($request->hasFile('profile_pic')) {
                $changed['profile_pic'] = 'photo';
            }
            $user->save();
            if ($changed) {
                ActivityLog::record('student.changed', $user->id, "$name changed: " . implode(', ', $changed));
            }
        });

        // Only after the change is saved.
        if ($oldPhoto && $oldPhoto !== $user->profile_pic && file_exists(public_path('image/profile/' . $oldPhoto))) {
            unlink(public_path('image/profile/' . $oldPhoto));
        }

        return redirect()->route('user.index')->with('success', "Changes to {$user->full_name} saved.");
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        DB::transaction(function () use ($user) {
            $user->delete(); // Soft delete
            ActivityLog::record('student.deleted', $user->id, "{$user->full_name} deleted");
        });

        return redirect()->route('user.index')->with('success', "{$user->full_name} deleted.");
    }

    public function status(Request $request, string $user)
    {
        $student = User::where('role', 'user')->findOrFail($user);
        $left = $request->validate(['status' => ['bail', 'required', 'string', 'in:left,active']])['status'] === 'left';

        DB::transaction(function () use ($student, $left) {
            // The same lock as money writes, so a bulk save cannot pass it.
            $locked = User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            if (($locked->left_on !== null) === $left) {
                return;
            }
            $locked->forceFill(['left_on' => $left ? today()->toDateString() : null])->save();
            ActivityLog::record($left ? 'student.left' : 'student.returned', $locked->id,
                $locked->full_name . ($left ? ' marked as left' : ' marked as active'));
        });

        return redirect()->route('user.show', $student->id)
            ->with('success', $student->full_name . ($left ? ' marked as left.' : ' marked as active.'));
    }
}

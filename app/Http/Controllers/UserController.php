<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserController extends Controller
{
    use ListsRecords;

    protected $messages = ['phone_number.regex' => 'Enter all 10 digits.'];

    protected $attributes = ['name' => 'first name', 'collage_name' => 'college', 'canteen_id' => 'canteen ID', 'profile_pic' => 'photo'];

    protected $validationRules = [
        'name' => ['required', 'string', 'min:2', 'max:30'],
        'middle_name' => ['nullable', 'string', 'min:2', 'max:30'],
        'last_name' => ['nullable', 'string', 'min:2', 'max:30'],
        'collage_name' => ['required', 'string', 'min:2', 'max:30'],
        'email' => ['required', 'email', 'regex:/^[\w\.\-]+@([\w\-]+\.)+[\w\-]{2,4}$/', 'max:255', 'unique:users,email'],
        'canteen_id' => ['required', 'string', 'min:1', 'max:10', 'unique:users,canteen_id'],
        'phone_number' => ['required', 'regex:/^[0-9]{10}$/'],
        'profile_pic' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,gif']
    ];

    protected function updateValidationRules($userId)
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:30'],
            'middle_name' => ['nullable', 'string', 'min:2', 'max:30'],
            'last_name' => ['nullable', 'string', 'min:2', 'max:30'],
            'collage_name' => ['required', 'string', 'min:2', 'max:30'],
            'email' => ['required', 'email', 'regex:/^[\w\.\-]+@([\w\-]+\.)+[\w\-]{2,4}$/', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'canteen_id' => ['required', 'string', 'min:1', 'max:10', Rule::unique('users', 'canteen_id')->ignore($userId)],
            'phone_number' => ['required', 'regex:/^[0-9]{10}$/'],
            'profile_pic' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,gif'],
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name', 'canteen_id', 'collage_name'], 'name');

        $query = User::where('role', 'user')->orderBy($filters['sort'], $filters['dir'])->orderBy('id');
        $this->searchIn($query, ['name', 'middle_name', 'last_name', 'email', 'canteen_id', 'collage_name'], $filters['search']);

        return Inertia::render('Students/Index', [
            'students' => $this->pageOf($query)->through(fn ($user) => $user->only(
                'id', 'name', 'middle_name', 'last_name', 'email', 'canteen_id', 'collage_name', 'profile_pic'
            )),
            'filters' => $filters,
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

        $validator = Validator::make($request->all(), $this->validationRules, $this->messages, $this->attributes);
        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)      // Pass validation errors to the session
                ->withInput($request->except('password'));  // Repopulate old input (except password)
        }
        $input = $request->all();
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
        $input['role'] =  "user";

        $user = User::create($input);

        if ($user) {
            return redirect()->route('user.index')->with('success', Str::squish($user->full_name) . ' added.');
        } else {
            return redirect()->route('user.index')->with('error', 'Something went wrong please try again later!');
        }
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
                'id', 'name', 'middle_name', 'last_name', 'email', 'phone_number', 'canteen_id', 'collage_name', 'profile_pic'
            ),
            'meals' => $combined,
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
        $rules = $this->updateValidationRules($id);
        $validator = Validator::make($request->all(), $rules, $this->messages, $this->attributes);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput($request->except('password'));
        }

        $input = $request->all();

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

            // Delete old image file if it exists and the filename is different
            if ($user->profile_pic && $user->profile_pic !== $fileName) {
                $oldFilePath = public_path('image/profile/' . $user->profile_pic);
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }

            $input['profile_pic'] = $fileName;
        } else {
            // Keep old profile_pic if no new file uploaded
            $input['profile_pic'] = $user->profile_pic;
        }

        // Update user with new input
        $updated = $user->update($input);

        if ($updated) {
            return redirect()->route('user.index')->with('success', 'Changes to ' . Str::squish($user->full_name) . ' saved.');
        } else {
            return redirect()->back()->with('error', 'Failed to update user, please try again.');
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete(); // Soft delete

        return redirect()->route('user.index')->with('success', Str::squish($user->full_name) . ' deleted.');
    }
}

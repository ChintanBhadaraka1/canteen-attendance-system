<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MealPrice;
use App\Models\Payment;
use App\Models\StudentAttendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsValidator;


class UserController extends Controller
{
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
    public function index()
    {
        return view('Users.index');
    }

    public function list(Request $request)
    {

        $columns = ['id', 'name', 'email', 'canteen_id', 'collage_name'];

        $length = $request->input('length');       // number of records per page
        $start = $request->input('start');         // offset

        $orderInput = $request->input('order');
        if (isset($orderInput[0]['column']) && isset($orderInput[0]['dir'])) {
            $orderColumnIndex = (int)$orderInput[0]['column'];
            $orderColumn = $columns[$orderColumnIndex];
            $orderDir = $orderInput[0]['dir'] === 'desc' ? 'desc' : 'asc';
        } else {
            // Default order
            $orderColumn = 'id';
            $orderDir = 'asc';
        }
        $search = $request->input('search.value'); // search input value

        // Base query
        $query = User::select('id', 'name', 'email', 'canteen_id', 'collage_name');
        $query = $query->whereNot('name', 'admin');

        // Apply search filter if not empty
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('canteen_id', 'like', "%{$search}%")
                    ->orWhere('collage_name', 'like', "%{$search}%");
            });
        }

        $totalFiltered = $query->count();

        // Apply ordering, pagination
        $users = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($length)
            ->get();

        if ($orderDir === 'asc') {
            $counter = $start + 1;
        } else {
            $counter = $totalFiltered - $start;
        }

        $data = [];
        if (!empty($users)) {
            foreach ($users as $user) {
                $action = view('Users.list_action', ['id' => $user->id])->render();
                $nestedData = [];
                $nestedData['id'] = $counter;
                $nestedData['name'] = $user->name;
                $nestedData['email'] = $user->email;
                $nestedData['canteen_id'] = $user->canteen_id;
                $nestedData['collage_name'] = $user->collage_name;
                $nestedData['action'] = $action;
                $data[] = $nestedData;
                if ($orderDir === 'asc') {
                    $counter++;
                } else {
                    $counter--;
                }
            }
        }

        $json_data = [
            "draw" => intval($request->input('draw')), // for security
            "recordsTotal" => User::count(),
            "recordsFiltered" => $totalFiltered,
            "data" => $data,
        ];

        return response()->json($json_data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $data['validator'] =  JsValidator::make($this->validationRules);

        return view('Users.create', $data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), $this->validationRules);
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
            return redirect()->route('user.index')->with('success', 'Data saved successfully!');
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
                'meal_id' => $meal->id,
                'meal_name' => $meal->name,
                'total' => $attendanceByMealId->has($meal->id) ? $attendanceByMealId->get($meal->id)->total : 0,
            ];
        });

        // If you want it as an array:
        $monthAttedanceData = $combined;

        if (count($mealNames) > 0) {
        }
       
        $paymentLabel = [
            'amount'      => "This month Month Bill",
            'pending_amount' => "Pending Payment Amount",
            'advance_amount'  => "Advance Amount",
        ];

        $paymentData = [
            'amount'      => 0,
            'pending_amount' => 0,
            'advance_amount' => 0,
        ];

        $studentPaymentData = Payment::select('amount', 'pending_amount', 'advance_amount')->where('student_id', $id)->first();

        if(!empty($studentPaymentData)){
            $paymentData = $studentPaymentData->toArray();
        }


        $data['user'] = $userData;
        $data['month_attendance_data']  = $monthAttedanceData;
        $data['payment_lable'] = $paymentLabel;
        $data['payment_data']  = $paymentData;


        return view('Users.show', $data);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $userData = User::findOrFail($id);
        $data['user'] = $userData;
        $data['validator'] =  JsValidator::make($this->updateValidationRules($id));

        return view('Users.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        // Find the user or fail with 404
        $user = User::findOrFail($id);
        $rules = $this->updateValidationRules($id);
        $validator = Validator::make($request->all(), $rules);

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
            return redirect()->route('user.index')->with('success', 'User updated successfully!');
        } else {
            return redirect()->back()->with('error', 'Failed to update user, please try again.');
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $user = User::findOrFail($id);
            $user->delete(); // Soft delete

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully .',
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user. Please try again later.',
                'error' => $e->getMessage(),  // Optional: remove in production
            ], 500);
        }
    }
}

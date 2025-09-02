<?php

namespace App\Http\Controllers;

use App\Models\Menus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsValidator;



class MenusController extends Controller
{
    protected $validationRules = [
        'name' => ['required', 'string', 'min:2', 'max:30'],
        'is_extra' => ['nullable'],
        'price' => ['nullable', 'numeric', 'min:0', 'required_if:is_extra,on'],
        'images' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,gif'],
    ];

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('Menus.index');
    }

    public function list(Request $request)
    {

        $columns = ['id', 'name', 'slug', 'is_extra', 'price', 'images'];

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
        $query = Menus::select('id', 'name', 'slug', 'is_extra', 'images');

        // Apply search filter if not empty
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('is_extra', 'like', "%{$search}%");
            });
        }

        $totalFiltered = $query->count();

        // Apply ordering, pagination
        $menus = $query->orderBy($orderColumn, $orderDir)
            ->offset($start)
            ->limit($length)
            ->get();

        if ($orderDir === 'asc') {
            $counter = $start + 1;
        } else {
            $counter = $totalFiltered - $start;
        }

        $data = [];
        if (!empty($menus)) {
            foreach ($menus as $menu) {
                $action = view('Menus.list_action', ['id' => $menu->id])->render();
                $nestedData = [];
                $nestedData['id'] = $counter;
                $nestedData['name'] = $menu->name;
                $nestedData['is_extra'] = $menu->is_extra;
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
            "recordsTotal" => Menus::count(),
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
        $data['validator']=  JsValidator::make($this->validationRules);

        return view('Menus.create',$data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), $this->validationRules);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        $input = $request->all();

        $input['slug'] = Str::slug($input['name']);

        if (isset($input['is_extra'])) {
            $input['is_extra'] = ($input['is_extra'] === 'on') ? '1' : '0';
        }

        $fileName = null;
        if ($request->hasFile('images')) {

            $file = $request->file('images');
            $extension = $file->getClientOriginalExtension();
            $random = random_int(1000000000, 9999999999);
            $fileName = $input['slug'] . '_' . $random . '.' . $extension;
            $destinationPath = public_path('image/menus');

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }
            $file->move($destinationPath, $fileName);
        }
        $input['images'] =  $fileName;


        $menuCreate = Menus::create($input);

        if ($menuCreate) {
            return redirect()->route('menus.index')->with('success', 'Data saved successfully!');
        } else {
            return redirect()->route('menus.index')->with('error', 'Something went wrong please try again later!');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Menus $menus)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $menuData = Menus::findOrFail($id);
        $data['menu'] = $menuData;
        $data['validator']=  JsValidator::make($this->validationRules);
        return view('Menus.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $menu = Menus::findOrFail($id);

        // Validate the request with your existing rules (you can reuse the same $this->validationRules or define update rules if needed)
        $validator = Validator::make($request->all(), $this->validationRules);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $input = $request->all();

        // Update slug from name
        $input['slug'] = Str::slug($input['name']);

        // Handle checkbox is_extra conversion from 'on' to 1, else 0
        if (isset($input['is_extra'])) {
            $input['is_extra'] = ($input['is_extra'] === 'on') ? '1' : '0';
        } else {
            $input['is_extra'] = '0';
        }

        $fileName = $menu->images; // Keep existing image name by default

        if ($request->hasFile('images')) {
            $file = $request->file('images');
            $extension = $file->getClientOriginalExtension();
            $random = random_int(1000000000, 9999999999);
            $fileName = $input['slug'] . '_' . $random . '.' . $extension;

            $destinationPath = public_path('image/menus');

            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $file->move($destinationPath, $fileName);

            // Delete old image file if exists and different filename
            if ($menu->images && $menu->images !== $fileName) {
                $oldFile = public_path('image/menus/' . $menu->images);
                if (file_exists($oldFile)) {
                    unlink($oldFile);
                }
            }
        }

        $input['images'] = $fileName;

        // dd($input);
        // Update the menu with new data
        $updated = $menu->update($input);

        if ($updated) {
            return redirect()->route('menus.index')->with('success', 'Data updated successfully!');
        } else {
            return redirect()->route('menus.index')->with('error', 'Something went wrong, please try again later!');
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $user = Menus::findOrFail($id);
            $user->delete(); // Soft delete

            return response()->json([
                'success' => true,
                'message' => 'Menu item deleted successfully .',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete Menu item. Please try again later.',
                'error' => $e->getMessage(), 
            ], 500);
        }
    }
}

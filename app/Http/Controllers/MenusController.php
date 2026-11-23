<?php

namespace App\Http\Controllers;

use App\Models\Menus;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;


class MenusController extends Controller
{
    use ListsRecords;

    protected $messages = ['price.required_if' => 'Enter a price for this extra item.'];

    protected $validationRules = [
        'name' => ['required', 'string', 'min:2', 'max:30'],
        'is_extra' => ['nullable'],
        'price' => ['nullable', 'numeric', 'min:0', 'required_if:is_extra,on'],
        'images' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,gif'],
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name'], 'name');

        $query = Menus::orderBy('name', $filters['dir'])->orderBy('id');
        $this->searchIn($query, ['name'], $filters['search']);

        return Inertia::render('Menu/Index', [
            'items' => $this->pageOf($query)->through(fn ($menu) => $this->item($menu)),
            'filters' => $filters,
        ]);
    }

    private function item(Menus $menu): array
    {
        return ['is_extra' => $menu->is_extra == '1'] + $menu->only('id', 'name', 'price', 'images');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Menu/Form', ['item' => null]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), $this->validationRules, $this->messages, ['images' => 'photo']);

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
            return redirect()->route('menus.index')->with('success', $menuCreate->name . ' added.');
        } else {
            return redirect()->route('menus.index')->with('error', 'Something went wrong please try again later!');
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        return Inertia::render('Menu/Form', ['item' => $this->item(Menus::findOrFail($id))]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $menu = Menus::findOrFail($id);

        // Validate the request with your existing rules (you can reuse the same $this->validationRules or define update rules if needed)
        $validator = Validator::make($request->all(), $this->validationRules, $this->messages, ['images' => 'photo']);

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

        // Update the menu with new data
        $updated = $menu->update($input);

        if ($updated) {
            return redirect()->route('menus.index')->with('success', 'Changes to ' . $menu->name . ' saved.');
        } else {
            return redirect()->route('menus.index')->with('error', 'Something went wrong, please try again later!');
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $menu = Menus::findOrFail($id);
        $menu->delete();

        return redirect()->route('menus.index')->with('success', $menu->name . ' deleted.');
    }
}

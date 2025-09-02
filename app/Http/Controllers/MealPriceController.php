<?php

namespace App\Http\Controllers;

use App\Models\MealPrice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsValidator;


class MealPriceController extends Controller
{
    protected $validationRules = [
        'name' => ['required', 'string', 'min:2', 'max:30'],
        'price' => ['required', 'numeric', 'min:0'],
    ];

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $mealData = MealPrice::get();
        $data['meal_data'] = $mealData;
        return view('Meals.index', $data);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $data['validator']=  JsValidator::make($this->validationRules);
        return view('Meals.create',$data);
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

        $mealPrice = MealPrice::create($input);

        if ($mealPrice) {
            return redirect()->route('meal-price.index')->with('success', 'Data saved successfully!');
        } else {
            return redirect()->route('meal-price.index')->with('error', 'Something went wrong please try again later!');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(MealPrice $mealPrice)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $mealData = MealPrice::findOrFail($id);
        $data['meal'] = $mealData;
        $data['validator']=  JsValidator::make($this->validationRules);

        return view('Meals.edit', $data);
    }

    /**
     * Update the specified resource in storage.
     */
     public function update(Request $request, $id)
    {
        $meal = MealPrice::findOrFail($id);

        // Validate the request with your existing rules (you can reuse the same $this->validationRules or define update rules if needed)
        $validator = Validator::make($request->all(), $this->validationRules);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $input = $request->all();

        $input['slug'] = Str::slug($input['name']);

        $updated = $meal->update($input);

        if ($updated) {
            return redirect()->route('meal-price.index')->with('success', 'Data updated successfully!');
        } else {
            return redirect()->route('meal-price.index')->with('error', 'Something went wrong, please try again later!');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $user = MealPrice::findOrFail($id);
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Meal  deleted successfully .',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete Meal . Please try again later.',
                'error' => $e->getMessage(), 
            ], 500);
        }
    }
}

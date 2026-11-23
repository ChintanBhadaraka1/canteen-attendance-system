<?php

namespace App\Http\Controllers;

use App\Models\MealPrice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;

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
        return Inertia::render('MealPrices/Index', ['meals' => MealPrice::get(['id', 'name', 'price'])]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('MealPrices/Form', ['meal' => null]);
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
            return redirect()->route('meal-price.index')->with('success', $mealPrice->name . ' added.');
        } else {
            return redirect()->route('meal-price.index')->with('error', 'Something went wrong please try again later!');
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        return Inertia::render('MealPrices/Form', ['meal' => MealPrice::findOrFail($id)->only('id', 'name', 'price')]);
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
            return redirect()->route('meal-price.index')->with('success', 'Changes to ' . $meal->name . ' saved.');
        } else {
            return redirect()->route('meal-price.index')->with('error', 'Something went wrong, please try again later!');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $meal = MealPrice::findOrFail($id);
        $meal->delete();

        return redirect()->route('meal-price.index')->with('success', $meal->name . ' deleted.');
    }
}

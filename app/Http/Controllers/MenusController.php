<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MenuOption;
use App\Models\Menus;
use App\Http\Controllers\Concerns\ListsRecords;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;


class MenusController extends Controller
{
    use ListsRecords;

    protected $messages = ['price.required_if' => 'Enter a price for this extra item.'];

    protected $validationRules = [
        'name' => ['required', 'string', 'min:2', 'max:30'],
        'is_extra' => ['nullable'],
        'price' => ['bail', 'nullable', 'required_if:is_extra,on', 'integer', 'between:0,100000'],
        'food_type_id' => ['nullable', 'integer'],
        'category_id' => ['nullable', 'integer'],
        'description' => ['nullable', 'string', 'max:200'],
        'available' => ['sometimes', 'required', 'boolean'],
        'images' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,gif'],
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $filters = $this->listParams($request, ['name'], 'name');

        $query = Menus::with('foodType', 'category')->orderBy('name', $filters['dir'])->orderBy('id');
        $this->searchIn($query, ['name'], $filters['search']);
        foreach (['type' => 'food_type', 'category' => 'category'] as $key => $kind) {
            $option = MenuOption::of($kind)->find((int) $this->text($request, $key));
            $filters[$key] = $option?->id ?? '';
            if ($option) {
                $query->where("{$kind}_id", $option->id);
            }
        }
        $availability = $this->text($request, 'availability');
        $filters['availability'] = in_array($availability, ['yes', 'no'], true) ? $availability : '';
        if ($filters['availability'] !== '') {
            $query->where('available', $filters['availability'] === 'yes');
        }

        return Inertia::render('Menu/Index', [
            'items' => $this->pageOf($query)->through(fn ($menu) => $this->item($menu)),
            'filters' => $filters,
            'options' => $this->options(),
        ]);
    }

    private function item(Menus $menu): array
    {
        return ['is_extra' => $menu->is_extra == '1'] + $menu->only(
            'id', 'name', 'price', 'images', 'food_type_id', 'category_id', 'description', 'available'
        ) + ['food_type' => $menu->foodType?->name, 'category' => $menu->category?->name];
    }

    // Options that are on, and the ones this item already uses.
    private function options(?Menus $menu = null, bool $all = true): array
    {
        return collect(['food_type', 'category'])->mapWithKeys(fn ($kind) => [
            $kind => MenuOption::of($kind)->get()
                ->filter(fn ($option) => $all || $option->active || $option->id === $menu?->{"{$kind}_id"})
                ->map(fn ($option) => ['id' => $option->id, 'name' => $option->name . ($option->active ? '' : ' (off)')])->values(),
        ])->all();
    }

    // Locks the chosen options, so they cannot be deleted before the item is saved.
    private function lockOptions(array $input, ?Menus $menu = null): void
    {
        $ids = array_filter(array_intersect_key($input, ['food_type_id' => 1, 'category_id' => 1]));
        $options = MenuOption::whereKey(array_values($ids))->orderBy('id')->sharedLock()->get()->keyBy('id');
        foreach ($ids as $field => $id) {
            $option = $options->get((int) $id);
            if ("{$option?->kind}_id" !== $field || (! $option->active && $menu?->$field !== $option->id)) {
                throw ValidationException::withMessages([$field => 'Choose one from the list.']);
            }
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Menu/Form', ['item' => null, 'options' => $this->options(all: false)]);
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
        $input = $validator->validated();

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


        $menu = DB::transaction(function () use ($input) {
            $this->lockOptions($input);
            $menu = Menus::create($input);
            ActivityLog::record('menu.added', null, $menu->name . ' added' . ($menu->price === null ? '' : " (₹{$menu->price})"));

            return $menu;
        });

        return redirect()->route('menus.index')->with('success', $menu->name . ' added.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $menu = Menus::findOrFail($id);

        return Inertia::render('Menu/Form', ['item' => $this->item($menu), 'options' => $this->options($menu, false)]);
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

        $input = $validator->validated();

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
        }

        $input['images'] = $fileName;

        $oldPhoto = $menu->images;
        DB::transaction(function () use ($menu, $input) {
            $this->lockOptions($input, $menu);
            $menu->fill($input);
            $summary = ActivityLog::describe($menu, ['name' => 'name', 'price' => 'price', 'is_extra' => 'extra item', 'images' => 'photo', 'food_type_id' => 'food type', 'category_id' => 'category', 'description' => 'description', 'available' => $menu->available ? 'marked available' : 'marked not available']);
            $menu->save();
            if ($summary !== '') {
                ActivityLog::record('menu.changed', null, $summary);
            }
        });

        // Only after the change is saved.
        if ($oldPhoto && $oldPhoto !== $menu->images && file_exists(public_path('image/menus/' . $oldPhoto))) {
            unlink(public_path('image/menus/' . $oldPhoto));
        }

        return redirect()->route('menus.index')->with('success', 'Changes to ' . $menu->name . ' saved.');
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        [$menu, $planned] = DB::transaction(function () use ($id) {
            // First query on purpose: planning and marking take a shared lock on the item.
            $menu = Menus::whereKey($id)->lockForUpdate()->firstOrFail();
            $planned = DB::table('menu_plan_items')->where('menu_id', $menu->id)->exists();
            if (! $planned) {
                $menu->delete();
                ActivityLog::record('menu.deleted', null, $menu->name . ' deleted');
            }

            return [$menu, $planned];
        }, 3);

        return $planned
            ? redirect()->route('menus.index')->with('error', $menu->name . ' is on the menu. Mark it as not available instead.')
            : redirect()->route('menus.index')->with('success', $menu->name . ' deleted.');
    }
}

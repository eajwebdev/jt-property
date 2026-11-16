<?php

namespace App\Http\Controllers;

use App\Models\ChargeCategory;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChargeCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('charge_categories', 'name')],
            'kind' => ['required', Rule::in(ChargeCategory::KINDS)],
        ]);

        $category = ChargeCategory::create(['name' => trim($data['name']), 'kind' => $data['kind']]);
        Audit::log('charge_category.created', $category, ['new' => $category->only(['name', 'kind'])]);

        return back()->with('success', "Category {$category->name} added.");
    }

    /** Only the name of a custom category can change; the built-in ones are fixed. */
    public function update(Request $request, ChargeCategory $category): RedirectResponse
    {
        Gate::authorize('owner');
        abort_if($category->is_system, 403, 'Built-in categories cannot be renamed.');

        $data = $request->validate(['name' => ['required', 'string', 'max:60', Rule::unique('charge_categories', 'name')->ignore($category->id)]]);

        $old = $category->name;
        $category->update(['name' => trim($data['name'])]);
        if ($category->wasChanged('name')) {
            Audit::log('charge_category.renamed', $category, ['old' => ['name' => $old], 'new' => ['name' => $category->name]]);
        }

        return back()->with('success', 'Category saved.');
    }
}

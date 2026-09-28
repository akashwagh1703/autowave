<?php

namespace App\Http\Controllers\App;

use App\Domain\Service\Actions\DeleteServiceCategory;
use App\Domain\Service\Actions\SaveServiceCategory;
use App\Domain\Service\Models\ServiceCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceCategoryController extends Controller
{
    public function store(Request $request, SaveServiceCategory $saveCategory): RedirectResponse
    {
        $category = $saveCategory->handle($this->validatedName($request));

        return back()->with('success', __('Category :name added.', ['name' => $category->name]));
    }

    public function update(Request $request, ServiceCategory $category, SaveServiceCategory $saveCategory): RedirectResponse
    {
        $saveCategory->handle($this->validatedName($request), $category);

        return back()->with('success', __('Category renamed.'));
    }

    public function destroy(ServiceCategory $category, DeleteServiceCategory $deleteCategory): RedirectResponse
    {
        $deleteCategory->handle($category);

        return back()->with('success', __('Category deleted. Its services are now uncategorised.'));
    }

    private function validatedName(Request $request): string
    {
        $request->merge(['name' => Str::squish((string) $request->input('name'))]);

        return $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']])['name'];
    }
}

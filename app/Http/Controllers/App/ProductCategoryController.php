<?php

namespace App\Http\Controllers\App;

use App\Domain\Commerce\Actions\DeleteProductCategory;
use App\Domain\Commerce\Actions\SaveProductCategory;
use App\Domain\Commerce\Models\ProductCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    public function store(Request $request, SaveProductCategory $saveCategory): RedirectResponse
    {
        $category = $saveCategory->handle($this->validatedName($request));

        return back()->with('success', __('Category :name added.', ['name' => $category->name]));
    }

    public function update(Request $request, ProductCategory $productCategory, SaveProductCategory $saveCategory): RedirectResponse
    {
        $saveCategory->handle($this->validatedName($request), $productCategory);

        return back()->with('success', __('Category renamed.'));
    }

    public function destroy(ProductCategory $productCategory, DeleteProductCategory $deleteCategory): RedirectResponse
    {
        $deleteCategory->handle($productCategory);

        return back()->with('success', __('Category deleted. Its products are now uncategorised.'));
    }

    private function validatedName(Request $request): string
    {
        $request->merge(['name' => Str::squish((string) $request->input('name'))]);

        return $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']])['name'];
    }
}

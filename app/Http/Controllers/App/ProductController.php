<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Actions\DeleteProduct;
use App\Domain\Commerce\Actions\SaveProduct;
use App\Domain\Commerce\Actions\SetProductImage;
use App\Domain\Commerce\Actions\StockLedger;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Commerce\Models\StockMovement;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CommercePresenter;
use App\Http\Presenters\CrmPresenter;
use App\Http\Requests\Commerce\ProductRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public const STATUSES = ['all', 'active', 'inactive'];

    public const STOCK = ['all', 'low', 'out'];

    public const SORTS = ['category', 'name', 'price', 'stock', 'newest'];

    public const BULK_LIMIT = 100;

    public const MOVEMENTS_SHOWN = 50;

    public function index(Request $request): Response
    {
        $category = $request->string('category')->toString();

        $filters = [
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
            'category' => $category === 'none' || ctype_digit($category) ? $category : null,
            'status' => in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'all',
            'stock' => in_array($request->query('stock'), self::STOCK, true) ? $request->query('stock') : 'all',
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'category',
        ];

        $query = Product::query()->with(['category', 'image']);

        $query->when($filters['search'], function (Builder $query, string $search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('sku', 'ilike', $like)->orWhere('description', 'ilike', $like));
        });

        match (true) {
            $filters['category'] === 'none' => $query->whereNull('product_category_id'),
            $filters['category'] !== null => $query->where('product_category_id', (int) $filters['category']),
            default => null,
        };

        match ($filters['status']) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => null,
        };

        match ($filters['stock']) {
            'low' => $query->lowStock(),
            'out' => $query->where('track_stock', true)->where('stock_quantity', 0),
            default => null,
        };

        match ($filters['sort']) {
            'name' => $query->orderBy('name')->orderBy('id'),
            'price' => $query->orderByDesc('price')->orderBy('name'),
            'stock' => $query->orderByDesc('track_stock')->orderBy('stock_quantity')->orderBy('name'),
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query
                ->orderByRaw('(select sort_order from product_categories where product_categories.id = products.product_category_id) asc nulls last')
                ->orderBy('sort_order')->orderBy('name')->orderBy('id'),
        };

        $products = $query->paginate(config('commerce.per_page'))->withQueryString();

        return Inertia::render('business/products/Index', [
            'products' => CrmPresenter::paginated($products, fn (Product $product) => CommercePresenter::product($product)),
            'filters' => $filters,
            'categories' => $this->categories(withCounts: true),
            'counts' => [
                'all' => Product::query()->count(),
                'active' => Product::query()->where('is_active', true)->count(),
                'uncategorised' => Product::query()->whereNull('product_category_id')->count(),
                'low' => Product::query()->lowStock()->count(),
                'out' => Product::query()->where('track_stock', true)->where('stock_quantity', 0)->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('business/products/Create', [
            'categories' => $this->categories(),
            'defaultCategoryId' => $request->integer('category') ?: null,
            'defaultLowStock' => (int) config('commerce.low_stock_threshold'),
            'foodTypes' => CommercePresenter::foodTypes(),
        ]);
    }

    public function store(ProductRequest $request, SaveProduct $saveProduct, SetProductImage $setImage): RedirectResponse
    {
        $product = DB::transaction(function () use ($request, $saveProduct, $setImage) {
            $product = $saveProduct->handle($request->productData(), actor: $request->user());

            if ($request->hasFile('image')) {
                $setImage->upload($product, $request->file('image'), $request->user());
            }

            return $product;
        });

        return to_route('products.index')->with('success', __(':name added.', ['name' => $product->name]));
    }

    public function edit(Product $product): Response
    {
        $product->load(['category', 'image']);

        return Inertia::render('business/products/Edit', [
            'product' => CommercePresenter::product($product),
            'categories' => $this->categories(),
            'defaultLowStock' => (int) config('commerce.low_stock_threshold'),
            'foodTypes' => CommercePresenter::foodTypes(),
            'movements' => StockMovement::query()
                ->where('product_id', $product->id)
                ->with(['order:id,number', 'creator:id,name'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(self::MOVEMENTS_SHOWN)
                ->get()
                ->map(fn (StockMovement $movement) => CommercePresenter::movement($movement)),
            'stockReasons' => CommercePresenter::stockReasons(),
            'openOrdersCount' => Order::query()->open()
                ->whereHas('items', fn (Builder $item) => $item->where('product_id', $product->id))
                ->count(),
        ]);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $saveProduct): RedirectResponse
    {
        $saveProduct->handle($request->productData(), $product, $request->user());

        return to_route('products.index')->with('success', __('Product updated.'));
    }

    public function destroy(Product $product, DeleteProduct $deleteProduct): RedirectResponse
    {
        $deleteProduct->handle($product);

        return to_route('products.index')->with('success', __('Product deleted.'));
    }

    public function stock(Request $request, Product $product, StockLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['add', 'remove', 'set'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:'.config('commerce.limits.max_stock')],
            'reason' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = $ledger->adjust($product, $validated, $request->user());

        return back()->with('success', $movement
            ? __('Stock updated. :name now has :count in stock.', ['name' => $product->name, 'count' => $movement->balance_after])
            : __('Stock is unchanged.'));
    }

    public function uploadImage(Request $request, Product $product, SetProductImage $setImage): RedirectResponse
    {
        $request->validate(['image' => ['required', 'file']]);
        $setImage->upload($product, $request->file('image'), $request->user());

        return back()->with('success', __('Image updated.'));
    }

    public function removeImage(Product $product, SetProductImage $setImage): RedirectResponse
    {
        $setImage->remove($product);

        return back()->with('success', __('Image removed.'));
    }

    public function bulk(Request $request, DeleteProduct $deleteProduct, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'available', 'unavailable', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'ids.*' => ['integer', 'distinct'],
        ]);

        abort_unless($request->user()->can($validated['action'] === 'delete' ? 'products.delete' : 'products.update'), 403);

        // Tenant-scoped: ids from another tenant simply do not match.
        $products = Product::query()->whereKey($validated['ids'])->get();

        DB::transaction(function () use ($validated, $products, $deleteProduct, $audit) {
            match ($validated['action']) {
                'activate' => Product::query()->whereKey($products->modelKeys())->update(['is_active' => true]),
                'deactivate' => Product::query()->whereKey($products->modelKeys())->update(['is_active' => false]),
                'available' => Product::query()->whereKey($products->modelKeys())->update(['is_available' => true]),
                'unavailable' => Product::query()->whereKey($products->modelKeys())->update(['is_available' => false]),
                'delete' => $products->each(fn (Product $product) => $deleteProduct->handle($product)),
            };

            $audit->log("products.bulk_{$validated['action']}", null, ['product_ids' => $products->modelKeys()]);
        });

        return back()->with('success', trans_choice('{1} :count product updated.|[2,*] :count products updated.', $products->count(), ['count' => $products->count()]));
    }

    /** @return list<array<string, mixed>> */
    private function categories(bool $withCounts = false): array
    {
        return ProductCategory::query()
            ->when($withCounts, fn (Builder $query) => $query->withCount('products'))
            ->ordered()
            ->get()
            ->map(fn (ProductCategory $category) => CommercePresenter::category($category))
            ->all();
    }
}

<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Models\Product;
use App\Domain\Media\Actions\SetRecordImage;
use App\Domain\Service\Actions\DeleteService;
use App\Domain\Service\Actions\SaveService;
use App\Domain\Service\Models\Service;
use App\Domain\Service\Models\ServiceCategory;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BookingOptions;
use App\Http\Presenters\BookingPresenter;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\FilesPresenter;
use App\Http\Requests\Booking\ServiceRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public const STATUSES = ['all', 'active', 'inactive', 'packages', 'services'];

    public const SORTS = ['category', 'name', 'price', 'duration', 'newest'];

    public const BULK_LIMIT = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly BookingOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $category = $request->string('category')->toString();

        $filters = [
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
            'category' => $category === 'none' || ctype_digit($category) ? $category : null,
            'status' => in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : 'all',
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'category',
        ];

        $query = Service::query()->with('category')->withCount('resources');

        $query->when($filters['search'], function (Builder $query, string $search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'ilike', $like)->orWhere('description', 'ilike', $like));
        });

        match (true) {
            $filters['category'] === 'none' => $query->whereNull('service_category_id'),
            $filters['category'] !== null => $query->where('service_category_id', (int) $filters['category']),
            default => null,
        };

        match ($filters['status']) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'packages' => $query->packages(),
            'services' => $query->standalone(),
            default => null,
        };

        match ($filters['sort']) {
            'name' => $query->orderBy('name')->orderBy('id'),
            'price' => $query->orderByDesc('price')->orderBy('name'),
            'duration' => $query->orderBy('duration_minutes')->orderBy('name'),
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query
                ->orderByRaw('(select sort_order from service_categories where service_categories.id = services.service_category_id) asc nulls last')
                ->orderBy('sort_order')->orderBy('name')->orderBy('id'),
        };

        $services = $query->paginate(config('booking.per_page'))->withQueryString();

        return Inertia::render('business/services/Index', [
            'services' => CrmPresenter::paginated($services, fn (Service $service) => BookingPresenter::service($service)),
            'filters' => $filters,
            'categories' => ServiceCategory::query()->withCount('services')->ordered()->get()
                ->map(fn (ServiceCategory $category) => BookingPresenter::category($category)),
            'counts' => [
                'all' => Service::query()->count(),
                'active' => Service::query()->where('is_active', true)->count(),
                'packages' => Service::query()->packages()->count(),
                'uncategorised' => Service::query()->whereNull('service_category_id')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('business/services/Create', [
            'categories' => $this->options->categories(),
            'resources' => $this->bookingResources(),
            'packageOptions' => $this->packageOptions(),
            'defaultCategoryId' => $request->integer('category') ?: null,
            'asPackage' => $request->boolean('package'),
        ]);
    }

    public function store(ServiceRequest $request, SaveService $saveService): RedirectResponse
    {
        $service = $saveService->handle($request->serviceData(), actor: $request->user());

        return to_route('services.index')->with('success', __(':name added.', ['name' => $service->name]));
    }

    public function edit(Request $request, Service $service): Response
    {
        $service->load(['category', 'resources:id', 'image', 'packageItems.includedService:id,name', 'packageItems.product:id,name']);

        return Inertia::render('business/services/Edit', [
            'service' => BookingPresenter::service($service),
            'categories' => $this->options->categories(),
            'resources' => $this->bookingResources(),
            'packageOptions' => $this->packageOptions($service->id),
            'upcomingCount' => $service->appointments()->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>', now())->count(),
            'files' => FilesPresenter::card($service, 'service', route('services.attachments.store', $service), $request->user(), $this->context->tenant()),
        ]);
    }

    public function update(ServiceRequest $request, Service $service, SaveService $saveService): RedirectResponse
    {
        $saveService->handle($request->serviceData(), $service, $request->user());

        return to_route('services.index')->with('success', $service->fresh()->is_package ? __('Package updated.') : __('Service updated.'));
    }

    public function uploadImage(Request $request, Service $service, SetRecordImage $images): RedirectResponse
    {
        $request->validate(['image' => ['required', 'file']]);
        $images->upload($service, $request->file('image'), 'service', $request->user());

        return back()->with('success', __('Photo updated.'));
    }

    public function removeImage(Service $service, SetRecordImage $images): RedirectResponse
    {
        $images->remove($service);

        return back()->with('success', __('Photo removed.'));
    }

    public function destroy(Service $service, DeleteService $deleteService): RedirectResponse
    {
        $wasPackage = (bool) $service->is_package;
        $deleteService->handle($service);

        return to_route('services.index')->with('success', $wasPackage ? __('Package deleted.') : __('Service deleted.'));
    }

    public function bulk(Request $request, DeleteService $deleteService, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'ids.*' => ['integer', 'distinct'],
        ]);

        // Tenant-scoped: ids from another tenant simply do not match.
        $services = Service::query()->whereKey($validated['ids'])->get();

        DB::transaction(function () use ($validated, $services, $deleteService, $audit) {
            match ($validated['action']) {
                'activate' => Service::query()->whereKey($services->modelKeys())->update(['is_active' => true]),
                'deactivate' => Service::query()->whereKey($services->modelKeys())->update(['is_active' => false]),
                'delete' => $services->each(fn (Service $service) => $deleteService->handle($service)),
            };

            $audit->log("services.bulk_{$validated['action']}", null, ['service_ids' => $services->modelKeys()]);
        });

        return back()->with('success', trans_choice('{1} :count service updated.|[2,*] :count services updated.', $services->count(), ['count' => $services->count()]));
    }

    /** @return list<array<string, mixed>> resources for the "who offers this" picker (booking engine only) */
    private function bookingResources(): array
    {
        return $this->context->hasEngine('booking') ? $this->options->resources() : [];
    }

    /**
     * Standalone services and products that can be listed inside a package.
     *
     * @return array{services: list<array{id: int, name: string}>, products: list<array{id: int, name: string}>}
     */
    private function packageOptions(?int $excludeId = null): array
    {
        $services = Service::query()
            ->standalone()
            ->active()
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (Service $service) => ['id' => $service->id, 'name' => $service->name])
            ->all();

        $products = $this->context->hasEngine('commerce')
            ? Product::query()->active()->ordered()->get(['id', 'name'])
                ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name])
                ->all()
            : [];

        return ['services' => $services, 'products' => $products];
    }
}

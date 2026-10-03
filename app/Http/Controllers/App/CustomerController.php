<?php

namespace App\Http\Controllers\App;

use App\Domain\Activity\Actions\LogActivity;
use App\Domain\Activity\Models\Activity;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Actions\DeleteCustomer;
use App\Domain\Customer\Actions\UpdateCustomer;
use App\Domain\Customer\Models\Customer;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AiPresenter;
use App\Http\Presenters\BookingPresenter;
use App\Http\Presenters\CommercePresenter;
use App\Http\Presenters\CrmOptions;
use App\Http\Presenters\CrmPresenter;
use App\Http\Presenters\FilesPresenter;
use App\Http\Requests\Crm\CustomerRequest;
use App\Http\Requests\Crm\LogActivityRequest;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public const SORTS = ['newest', 'oldest', 'name'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CrmOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $filters = [
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
            'tag' => Str::limit(trim($request->string('tag')->toString()), 30, '') ?: null,
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'newest',
        ];

        $query = Customer::query()->withCount('leads');

        if ($filters['search']) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $digits = preg_replace('/\D/', '', $filters['search']);

            $query->where(function (Builder $query) use ($like, $digits) {
                $query->where('name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('city', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $query->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            });
        }

        $query->when($filters['tag'], fn (Builder $q, string $tag) => $q->whereJsonContains('tags', $tag));

        match ($filters['sort']) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $customers = $query->paginate(config('crm.per_page'))->withQueryString();

        return Inertia::render('business/customers/Index', [
            'customers' => CrmPresenter::paginated($customers, fn (Customer $customer) => CrmPresenter::customer($customer)),
            'filters' => $filters,
            'tags' => $this->tagOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('business/customers/Create', [
            'tags' => $this->tagOptions(),
        ]);
    }

    public function store(CustomerRequest $request, CreateCustomer $createCustomer): RedirectResponse
    {
        $customer = $createCustomer->handle($request->validated(), $request->user());

        return to_route('customers.show', $customer)->with('success', __('Customer added.'));
    }

    public function show(Request $request, Customer $customer): Response
    {
        $showAppointments = $this->context->hasEngine('booking') && $request->user()->can('appointments.view');
        $showOrders = $this->context->hasEngine('commerce') && $request->user()->can('orders.view');

        return Inertia::render('business/customers/Show', [
            'orders' => $showOrders
                ? Order::query()
                    ->where('customer_id', $customer->id)
                    ->with('items')
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->limit(20)
                    ->get()
                    ->map(fn (Order $order) => CommercePresenter::order($order))
                : null,
            'appointments' => $showAppointments
                ? Appointment::query()
                    ->where('customer_id', $customer->id)
                    ->with(['resource', 'service'])
                    ->orderByDesc('starts_at')
                    ->limit(20)
                    ->get()
                    ->map(fn (Appointment $appointment) => BookingPresenter::appointment($appointment))
                : null,
            'customer' => CrmPresenter::customer($customer),
            'ai' => AiPresenter::record($customer, $request->user()),
            'leads' => $customer->leads()
                ->with(['stage', 'assignee.user:id,name'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($lead) => CrmPresenter::lead($lead)),
            'activities' => Activity::query()
                ->where('customer_id', $customer->id)
                ->with(['user:id,name', 'lead'])
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->limit(100)
                ->get()
                ->map(fn (Activity $activity) => CrmPresenter::activity($activity)),
            'activityTypes' => $this->options->activityTypes(),
            'documents' => FilesPresenter::card($customer, 'customer', route('customers.attachments.store', $customer), $request->user(), $this->context->tenant()),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('business/customers/Edit', [
            'customer' => CrmPresenter::customer($customer),
            'tags' => $this->tagOptions(),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer, UpdateCustomer $updateCustomer): RedirectResponse
    {
        $updateCustomer->handle($customer, $request->validated(), $request->user());

        return to_route('customers.show', $customer)->with('success', __('Customer updated.'));
    }

    public function destroy(Customer $customer, DeleteCustomer $deleteCustomer): RedirectResponse
    {
        $deleteCustomer->handle($customer);

        return to_route('customers.index')->with('success', __('Customer deleted.'));
    }

    public function activity(LogActivityRequest $request, Customer $customer, LogActivity $logActivity): RedirectResponse
    {
        $logActivity->handle(
            $customer,
            $request->validated('type'),
            $request->validated('body'),
            $request->user(),
            TenantTime::parse($request->validated('occurred_at')),
        );

        return back()->with('success', __('Activity logged.'));
    }

    /** @return list<string> tags already used in this tenant, for autocomplete and filtering */
    private function tagOptions(): array
    {
        return DB::table('customers')
            ->where('tenant_id', $this->context->tenant()->id)
            ->whereNull('deleted_at')
            ->whereNotNull('tags')
            ->selectRaw('distinct jsonb_array_elements_text(tags) as tag')
            ->orderBy('tag')
            ->limit(100)
            ->pluck('tag')
            ->all();
    }
}

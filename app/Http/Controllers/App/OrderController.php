<?php

namespace App\Http\Controllers\App;

use App\Domain\Activity\Models\Activity;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CommercePresenter;
use App\Http\Presenters\CrmPresenter;
use App\Http\Requests\Commerce\OrderRequest;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public const VIEWS = ['open', 'all', 'pending', 'confirmed', 'ready', 'completed', 'cancelled'];

    public const RANGES = ['all', 'today', '7d', '30d'];

    public function index(Request $request): Response
    {
        $filters = [
            'search' => Str::limit(trim($request->string('search')->toString()), 100, '') ?: null,
            'status' => in_array($request->query('status'), self::VIEWS, true) ? $request->query('status') : 'open',
            'source' => array_key_exists((string) $request->query('source'), config('commerce.sources')) ? $request->query('source') : null,
            'payment' => PaymentStatus::tryFrom((string) $request->query('payment'))?->value,
            'range' => in_array($request->query('range'), self::RANGES, true) ? $request->query('range') : 'all',
        ];

        $query = Order::query()->with(['customer', 'items']);

        match ($filters['status']) {
            'open' => $query->open(),
            'all' => null,
            default => $query->where('status', $filters['status']),
        };

        $query->when($filters['source'], fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['payment'], fn (Builder $q, string $payment) => $q->where('payment_status', $payment)->notCancelled());

        $todayStart = TenantTime::now()->startOfDay()->utc();

        match ($filters['range']) {
            'today' => $query->where('created_at', '>=', $todayStart),
            '7d' => $query->where('created_at', '>=', $todayStart->copy()->subDays(6)),
            '30d' => $query->where('created_at', '>=', $todayStart->copy()->subDays(29)),
            default => null,
        };

        if ($filters['search']) {
            $search = ltrim($filters['search'], '#');
            $like = '%'.addcslashes($search, '%_\\').'%';
            $digits = preg_replace('/\D/', '', $search);

            $query->where(function (Builder $q) use ($search, $like, $digits) {
                if (ctype_digit($search) && strlen($search) <= 9) {
                    $q->where('number', (int) $search);
                }

                $q->orWhereHas('customer', fn (Builder $customer) => $customer->withTrashed()->where(function (Builder $c) use ($like, $digits) {
                    $c->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like);

                    if (strlen($digits) >= 4) {
                        $c->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                    }
                }));
            });
        }

        $orders = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(config('commerce.per_page'))->withQueryString();

        return Inertia::render('business/orders/Index', [
            'orders' => CrmPresenter::paginated($orders, fn (Order $order) => CommercePresenter::order($order)),
            'filters' => $filters,
            'counts' => [
                'open' => Order::query()->open()->count(),
                'pending' => Order::query()->where('status', OrderStatus::Pending)->count(),
                'ready' => Order::query()->where('status', OrderStatus::Ready)->count(),
                'today' => Order::query()->notCancelled()->where('created_at', '>=', $todayStart)->count(),
            ],
            'statuses' => CommercePresenter::statuses(),
            'paymentStatuses' => CommercePresenter::paymentStatuses(),
            'sources' => CommercePresenter::sources(),
        ]);
    }

    public function create(Request $request, CommerceSettings $settings): Response
    {
        $customer = $request->integer('customer') ? Customer::query()->find($request->integer('customer')) : null;

        return Inertia::render('business/orders/Create', [
            'products' => Product::query()->active()->with('category')->ordered()
                ->limit((int) config('commerce.limits.products_in_order_form'))
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'price' => (string) $product->price,
                    'category' => $product->category?->name,
                    'track_stock' => $product->track_stock,
                    'available' => $product->available(),
                ]),
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone, 'address' => $customer->address] : null,
            'fulfilmentOptions' => CommercePresenter::fulfilmentOptions(),
            'paymentMethods' => CommercePresenter::paymentMethods(),
            'defaultDeliveryFee' => $settings->online()['delivery_fee'],
            'limits' => [
                'items' => (int) config('commerce.limits.items_per_order'),
                'quantity' => (int) config('commerce.limits.max_quantity'),
            ],
        ]);
    }

    public function store(OrderRequest $request, PlaceOrder $placeOrder): RedirectResponse
    {
        $order = $placeOrder->handle($request->orderData(), $request->user());

        return to_route('orders.show', $order)->with('success', __('Order :number created for :name.', ['number' => $order->reference(), 'name' => $order->customer->name]));
    }

    public function show(Order $order): Response
    {
        $order->load(['customer', 'items.product', 'payments.recorder:id,name', 'creator:id,name']);

        return Inertia::render('business/orders/Show', [
            'order' => CommercePresenter::order($order),
            'transitions' => collect($order->status->allowedTransitions())
                ->map(fn (OrderStatus $status) => ['value' => $status->value, 'label' => $status->labelFor($order->fulfilment)])
                ->values(),
            'activities' => Activity::query()
                ->where('order_id', $order->id)
                ->with('user:id,name')
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->get()
                ->map(fn (Activity $activity) => CrmPresenter::activity($activity)),
            'paymentMethods' => CommercePresenter::paymentMethods(),
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $order->update(['notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null]);

        return back()->with('success', __('Order notes saved.'));
    }

    /** Customer lookup for the order form (JSON). */
    public function customers(Request $request): JsonResponse
    {
        $search = Str::limit(trim($request->string('search')->toString()), 100, '');

        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D/', '', $search);

        $customers = Customer::query()
            ->where(function (Builder $query) use ($like, $digits) {
                $query->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('phone', 'ilike', $like);

                if (strlen($digits) >= 4) {
                    $query->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone', 'email', 'address']);

        return response()->json(['data' => $customers->map(fn (Customer $customer) => $customer->only(['id', 'name', 'phone', 'email', 'address']))]);
    }
}

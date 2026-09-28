<?php

namespace App\Domain\AI\Assistant;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Models\Appointment;
use App\Domain\Booking\Services\BookingMetrics;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Services\CommerceMetrics;
use App\Domain\Customer\Models\Customer;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Lead\Services\CrmMetrics;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Service\Models\Service;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only tools the assistant may call (ADR-019). A tool is offered only when the user holds its
 * permission and the module/engine is on; it runs in the tenant scope and returns names, dates, counts
 * and amounts — never phone numbers or e-mail addresses.
 */
class AssistantTools
{
    private const MAX_DAYS = 92;

    public function __construct(private readonly TenantContext $context) {}

    /** @return list<array{name: string, description: string, parameters: array<string, mixed>}> */
    public function definitions(User $user): array
    {
        return array_values(array_map(
            fn (array $tool) => ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['parameters']],
            array_filter($this->catalogue(), fn (array $tool) => $this->allowed($tool, $user)),
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, User $user): array
    {
        $tool = collect($this->catalogue())->firstWhere('name', $name);

        if (! $tool || ! $this->allowed($tool, $user)) {
            return ['error' => 'This tool is not available.'];
        }

        try {
            return $this->{$tool['method']}($arguments, $user);
        } catch (Throwable $exception) {
            report($exception);

            return ['error' => 'The data could not be read.'];
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        $range = [
            'date_from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (business time zone). Defaults to today.'],
            'date_to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD, inclusive. Defaults to date_from.'],
        ];

        return [
            [
                'name' => 'business_overview',
                'method' => 'overview',
                'description' => 'Today\'s key figures: appointments, revenue, new leads, follow-ups due, orders, low stock, unread conversations.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'appointments',
                'method' => 'appointments',
                'engine' => 'booking',
                'permission' => 'appointments.view',
                'description' => 'Appointments in a date range, with counts by status and the list (time, customer first name, service, with whom, status).',
                'parameters' => ['type' => 'object', 'properties' => [
                    ...$range,
                    'status' => ['type' => 'string', 'enum' => array_column(AppointmentStatus::cases(), 'value')],
                ]],
            ],
            [
                'name' => 'leads',
                'method' => 'leads',
                'module' => 'leads',
                'permission' => 'leads.view',
                'description' => 'Leads with counts by stage and a list (name, stage, source, interest, estimated value, created, next follow-up). Filter by stage code, creation dates, follow-ups due or a name.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'stage' => ['type' => 'string', 'description' => 'Stage code: '.implode(', ', $this->stageCodes())],
                    'created_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'created_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'follow_up_due' => ['type' => 'boolean', 'description' => 'Only open leads with a follow-up due today or overdue.'],
                    'search' => ['type' => 'string', 'description' => 'Part of the lead name.'],
                ]],
            ],
            [
                'name' => 'customers',
                'method' => 'customers',
                'module' => 'customers',
                'permission' => 'customers.view',
                'description' => 'Find customers by name (up to 10), with city, tags, customer since and their last visit or order.',
                'parameters' => ['type' => 'object', 'properties' => ['search' => ['type' => 'string']], 'required' => ['search']],
            ],
            [
                'name' => 'orders',
                'method' => 'orders',
                'engine' => 'commerce',
                'permission' => 'orders.view',
                'description' => 'Orders placed in a date range: count, value of orders not cancelled, amount paid, counts by status and a list.',
                'parameters' => ['type' => 'object', 'properties' => [
                    ...$range,
                    'status' => ['type' => 'string', 'enum' => array_column(OrderStatus::cases(), 'value')],
                ]],
            ],
            [
                'name' => 'products',
                'method' => 'products',
                'engine' => 'commerce',
                'permission' => 'products.view',
                'description' => 'Products with price and stock. Filter by name or low stock.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'search' => ['type' => 'string'],
                    'low_stock' => ['type' => 'boolean'],
                ]],
            ],
            [
                'name' => 'services',
                'method' => 'services',
                'engine' => 'service',
                'permission' => 'services.view',
                'description' => 'The service menu: name, category, price, duration and whether it is active.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    /** @param  array<string, mixed>  $tool */
    private function allowed(array $tool, User $user): bool
    {
        return (! isset($tool['module']) || $this->context->hasModule($tool['module']))
            && (! isset($tool['engine']) || $this->context->hasEngine($tool['engine']))
            && (! isset($tool['permission']) || $user->can($tool['permission']));
    }

    /** @return array<string, mixed> */
    private function overview(array $arguments, User $user): array
    {
        $widgets = array_values(array_unique([
            ...(array) $this->context->setting('dashboard_widgets', []),
            'appointments_today', 'revenue_today', 'new_leads', 'pending_followups', 'orders_today', 'low_stock', 'new_customers',
        ]));

        $figures = [];

        foreach ([app(CrmMetrics::class), app(BookingMetrics::class), app(CommerceMetrics::class)] as $metrics) {
            foreach ($metrics->for($user, $widgets) as $key => $metric) {
                $figures[Str::headline($key)] = ['value' => $metric['value'], 'about' => $metric['hint']];
            }
        }

        if ($this->context->hasModule('messaging') && $user->can('conversations.view')) {
            $open = Conversation::query()->where('status', ConversationStatus::Open);
            $figures['Unread conversations'] = ['value' => (clone $open)->where('unread_count', '>', 0)->count(), 'about' => 'Open WhatsApp/Instagram conversations with unread messages'];
        }

        return ['today' => TenantTime::now()->toDateString(), 'figures' => $figures];
    }

    /** @return array<string, mixed> */
    private function appointments(array $arguments, User $user): array
    {
        [$from, $to] = $this->range($arguments);
        $query = Appointment::query()->where('starts_at', '>=', $from->copy()->utc())->where('starts_at', '<', $to->copy()->addDay()->utc());
        $counts = (clone $query)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();

        if (($status = AppointmentStatus::tryFrom((string) ($arguments['status'] ?? ''))) !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->with(['customer:id,tenant_id,name', 'service:id,tenant_id,name', 'resource:id,tenant_id,name'])
            ->orderBy('starts_at')->limit($this->rows())->get()
            ->map(fn (Appointment $appointment) => [
                'when' => $appointment->starts_at->setTimezone(TenantTime::timezone())->format('D j M, g:i A'),
                'customer' => $this->firstName($appointment->customer?->name),
                'service' => $appointment->service?->name,
                'with' => $appointment->resource?->name,
                'status' => $appointment->status->label(),
                'price' => $appointment->price !== null ? (float) $appointment->price : null,
            ])->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count_by_status' => $counts,
            'matching' => $total,
            'appointments' => $rows,
            'truncated' => $total > count($rows),
        ];
    }

    /** @return array<string, mixed> */
    private function leads(array $arguments, User $user): array
    {
        $query = Lead::query();

        if (filled($arguments['stage'] ?? null)) {
            $query->whereHas('stage', fn (Builder $stage) => $stage->where('code', (string) $arguments['stage']));
        }

        if ($from = $this->date($arguments['created_from'] ?? null)) {
            $query->where('leads.created_at', '>=', $from->copy()->utc());
        }

        if ($to = $this->date($arguments['created_to'] ?? null)) {
            $query->where('leads.created_at', '<', $to->copy()->addDay()->utc());
        }

        if (($arguments['follow_up_due'] ?? false) === true) {
            $query->followUpDue();
        }

        if (filled($arguments['search'] ?? null)) {
            $query->where('leads.name', 'ilike', '%'.addcslashes(Str::limit((string) $arguments['search'], 60, ''), '%_\\').'%');
        }

        $stageNames = LeadStage::query()->pluck('name', 'id');
        $byStage = collect((clone $query)->toBase()->selectRaw('lead_stage_id, count(*) as total')->groupBy('lead_stage_id')->pluck('total', 'lead_stage_id'))
            ->mapWithKeys(fn ($total, $stageId) => [(string) ($stageNames[$stageId] ?? 'Unknown') => (int) $total])
            ->all();
        $total = (clone $query)->count();
        $rows = $query->with(['stage:id,name', 'source:id,name'])->latest('id')->limit($this->rows())->get()
            ->map(fn (Lead $lead) => [
                'name' => $lead->name,
                'stage' => $lead->stage?->name,
                'source' => $lead->source?->name,
                'interest' => $lead->interest,
                'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
                'created' => $lead->created_at?->setTimezone(TenantTime::timezone())->format('j M Y'),
                'next_follow_up' => $lead->next_followup_at?->setTimezone(TenantTime::timezone())->format('j M, g:i A'),
            ])->all();

        return ['count_by_stage' => $byStage, 'matching' => $total, 'leads' => $rows, 'truncated' => $total > count($rows)];
    }

    /** @return array<string, mixed> */
    private function customers(array $arguments, User $user): array
    {
        $search = trim(Str::limit((string) ($arguments['search'] ?? ''), 60, ''));

        if ($search === '') {
            return ['customers' => [], 'note' => 'Give part of a customer name.'];
        }

        $customers = Customer::query()->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orderBy('name')->limit(10)->get();

        return ['customers' => $customers->map(function (Customer $customer) {
            $lastVisit = $this->context->hasEngine('booking')
                ? Appointment::query()->where('customer_id', $customer->id)->where('starts_at', '<=', now())->max('starts_at')
                : null;
            $lastOrder = $this->context->hasEngine('commerce')
                ? Order::query()->where('customer_id', $customer->id)->max('created_at')
                : null;

            return array_filter([
                'name' => $customer->name,
                'city' => $customer->city,
                'tags' => $customer->tags ?: null,
                'customer_since' => $customer->created_at?->setTimezone(TenantTime::timezone())->format('j M Y'),
                'last_visit' => $lastVisit ? Carbon::parse($lastVisit)->setTimezone(TenantTime::timezone())->format('j M Y') : null,
                'last_order' => $lastOrder ? Carbon::parse($lastOrder)->setTimezone(TenantTime::timezone())->format('j M Y') : null,
            ], fn ($value) => $value !== null);
        })->all()];
    }

    /** @return array<string, mixed> */
    private function orders(array $arguments, User $user): array
    {
        [$from, $to] = $this->range($arguments);
        $query = Order::query()->where('created_at', '>=', $from->copy()->utc())->where('created_at', '<', $to->copy()->addDay()->utc());
        $counts = (clone $query)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $notCancelled = (clone $query)->where('status', '!=', OrderStatus::Cancelled);
        $value = (string) ((clone $notCancelled)->sum('total') ?? 0);
        $paid = (string) ((clone $notCancelled)->sum('amount_paid') ?? 0);

        if (($status = OrderStatus::tryFrom((string) ($arguments['status'] ?? ''))) !== null) {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->with('customer:id,tenant_id,name')->latest('id')->limit($this->rows())->get()
            ->map(fn (Order $order) => [
                'order' => $order->reference(),
                'placed' => $order->created_at?->setTimezone(TenantTime::timezone())->format('j M, g:i A'),
                'customer' => $this->firstName($order->customer?->name),
                'items' => $order->itemSummary(3),
                'total' => (float) $order->total,
                'status' => $order->status->label(),
                'payment' => $order->payment_status->label(),
            ])->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count_by_status' => $counts,
            'value_not_cancelled' => (float) $value,
            'amount_paid' => (float) $paid,
            'matching' => $total,
            'orders' => $rows,
            'truncated' => $total > count($rows),
        ];
    }

    /** @return array<string, mixed> */
    private function products(array $arguments, User $user): array
    {
        $query = Product::query()->ordered();

        if (filled($arguments['search'] ?? null)) {
            $query->where('name', 'ilike', '%'.addcslashes(Str::limit((string) $arguments['search'], 60, ''), '%_\\').'%');
        }

        if (($arguments['low_stock'] ?? false) === true) {
            $query->lowStock();
        }

        return ['products' => $query->limit($this->rows())->get()->map(fn (Product $product) => [
            'name' => $product->name,
            'price' => (float) $product->price,
            'active' => $product->is_active,
            'stock' => $product->track_stock ? $product->stock_quantity : 'not tracked',
            'low_stock' => $product->isLowStock(),
        ])->all()];
    }

    /** @return array<string, mixed> */
    private function services(array $arguments, User $user): array
    {
        return ['services' => Service::query()->ordered()->with('category:id,name')->limit((int) config('ai.context.services'))->get()->map(fn (Service $service) => [
            'name' => $service->name,
            'category' => $service->category?->name,
            'price' => $service->price !== null ? (float) $service->price : null,
            'duration_minutes' => $service->duration_minutes,
            'active' => $service->is_active,
        ])->all()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: Carbon, 1: Carbon} start of the first and last day, in the business time zone
     */
    private function range(array $arguments): array
    {
        $from = $this->date($arguments['date_from'] ?? null) ?? TenantTime::now()->startOfDay();
        $to = $this->date($arguments['date_to'] ?? null) ?? $from->copy();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $to = $from->copy()->addDays(self::MAX_DAYS);
        }

        return [$from, $to];
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, TenantTime::timezone())->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function stageCodes(): array
    {
        return $this->context->check() && $this->context->hasModule('leads')
            ? LeadStage::query()->active()->orderBy('sort_order')->pluck('code')->all()
            : [];
    }

    private function firstName(?string $name): ?string
    {
        return $name ? Str::before(trim($name), ' ') : null;
    }

    private function rows(): int
    {
        return (int) config('ai.context.assistant_rows');
    }
}

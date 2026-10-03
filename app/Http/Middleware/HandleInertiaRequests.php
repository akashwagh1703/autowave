<?php

namespace App\Http\Middleware;

use App\Domain\AI\Services\AIGateway;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\RBAC\Support\PermissionResolver;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Presenters\BillingPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Only expose data that is safe for the browser. Never share secrets, tokens,
     * or another tenant's data here. Tenant props are closures because the tenant
     * is resolved by route middleware that runs after this one.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
            ],
            'auth' => [
                'user' => fn () => $request->user()?->only(['id', 'name', 'email']),
            ],
            'tenant' => function () {
                $context = app(TenantContext::class);
                $tenant = $context->get();

                if (! $tenant) {
                    return null;
                }

                $engines = $context->enabledEngines();

                return [
                    ...$tenant->only(['id', 'name', 'slug', 'timezone', 'currency', 'locale']),
                    'modules' => $context->enabledModules(),
                    'engines' => $engines,
                    'resource_label' => in_array('booking', $engines, true) ? app(BookingSettings::class)->resourceLabels() : null,
                ];
            },
            'permissions' => function () use ($request) {
                $user = $request->user();

                return $user && app(TenantContext::class)->check()
                    ? app(PermissionResolver::class)->permissionsFor($user)
                    : [];
            },
            // The nav badge: open conversations with unread messages.
            'inbox' => function () use ($request) {
                $context = app(TenantContext::class);
                $user = $request->user();

                if (! $user || ! $context->check() || ! $context->hasModule('messaging') || ! $user->can('conversations.view')) {
                    return null;
                }

                return ['unread' => Conversation::query()->where('status', ConversationStatus::Open)->where('unread_count', '>', 0)->count()];
            },
            // Whether AI helpers can be used right now (and why not). Never includes provider details or keys.
            'ai' => function () use ($request) {
                $context = app(TenantContext::class);
                $user = $request->user();

                if (! $user || ! $context->check() || ! $context->hasModule('ai') || ! $user->can('ai.use')) {
                    return null;
                }

                return app(AIGateway::class)->status();
            },
            // The plan banner: trial or plan ending, payment due, read-only or locked.
            'billing' => function () use ($request) {
                $tenant = app(TenantContext::class)->get();

                if (! $request->user() || ! $tenant || $tenant->is_internal || $request->getHost() !== config('autowave.hosts.app')) {
                    return null;
                }

                $entitlements = app(Entitlements::class);
                $subscription = BillingPresenter::subscription($tenant, $entitlements);

                return [
                    ...Arr::only($subscription, ['state', 'label', 'access', 'is_trial', 'ends_at', 'days_left', 'read_only_at', 'locks_at', 'unlimited']),
                    'plan' => $subscription['plan']['name'] ?? null,
                    'enforced' => app(BillingSettings::class)->enforced(),
                    'pending' => BillingPayment::query()->where('status', BillingPayment::PENDING)->exists(),
                    'can_manage' => $request->user()->can('billing.manage'),
                ];
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}

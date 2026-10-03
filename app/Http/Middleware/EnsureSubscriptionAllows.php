<?php

namespace App\Http\Middleware;

use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Business app routes after a plan ended (docs/05-features/billing.md). Read-only: pages open, but
 * anything that changes data is refused. Locked: only Billing opens. Billing routes always pass, so a
 * business can always pay. Does nothing while enforcement is off.
 */
class EnsureSubscriptionAllows
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->get();

        if (! $tenant || $request->routeIs('billing.*')) {
            return $next($request);
        }

        $access = $this->entitlements->access($tenant);

        if ($access === Entitlements::LOCKED) {
            $message = __('Your AutoWave plan has ended. Pay to unlock your business; nothing has been deleted.');

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $message], 403)
                : redirect()->route('billing.show')->with('error', $message);
        }

        if ($access === Entitlements::READ_ONLY && ! $request->isMethodSafe()) {
            $message = __('Your business is read-only because the plan has ended. Pay in Billing to make changes again.');

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $message], 403)
                : back()->with('error', $message);
        }

        return $next($request);
    }
}

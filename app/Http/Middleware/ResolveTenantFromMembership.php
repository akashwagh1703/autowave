<?php

namespace App\Http\Middleware;

use App\Domain\Tenant\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Business app: the tenant comes from the signed-in user's active membership.
 *
 * The session only stores a *preference* (current_tenant_id); it is re-validated
 * against the database on every request, so a removed or suspended membership
 * loses access immediately.
 */
class ResolveTenantFromMembership
{
    public const SESSION_KEY = 'current_tenant_id';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $preferred = $request->session()->get(self::SESSION_KEY);

        $membership = $user?->activeMembership(is_numeric($preferred) ? (int) $preferred : null);

        if (! $membership) {
            $request->session()->forget(self::SESSION_KEY);

            // Brand-new users set up their first business; users whose access was removed see why on /workspaces.
            return $user && ! $user->memberships()->exists()
                ? redirect()->route('onboarding.create')
                : redirect()->route('workspaces.index');
        }

        if ((int) $preferred !== $membership->tenant_id) {
            $request->session()->put(self::SESSION_KEY, $membership->tenant_id);
        }

        $this->context->set($membership->tenant);

        return $next($request);
    }
}

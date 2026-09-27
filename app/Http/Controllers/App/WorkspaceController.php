<?php

namespace App\Http\Controllers\App;

use App\Domain\Onboarding\Actions\OnboardBusiness;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenantFromMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lists the businesses the user belongs to and switches the current one.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $current = $request->session()->get(ResolveTenantFromMembership::SESSION_KEY);

        $workspaces = $request->user()
            ->accessibleTenants()
            ->with('businessType:id,name')
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'business_type' => $tenant->businessType?->name,
                'is_current' => (int) $current === $tenant->id,
            ]);

        return Inertia::render('business/Workspaces', [
            'workspaces' => $workspaces,
            'canCreate' => ! OnboardBusiness::limitReached($request->user()),
        ]);
    }

    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        $membership = $request->user()->activeMembership($tenant->id);

        abort_unless($membership && $membership->tenant_id === $tenant->id, 404);

        $request->session()->put(ResolveTenantFromMembership::SESSION_KEY, $tenant->id);

        return redirect()->route('dashboard')->with('success', __('Switched to :name.', ['name' => $tenant->name]));
    }
}

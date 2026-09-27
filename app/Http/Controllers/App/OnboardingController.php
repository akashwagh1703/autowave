<?php

namespace App\Http\Controllers\App;

use App\Domain\Domain\Support\Hostname;
use App\Domain\Onboarding\Actions\OnboardBusiness;
use App\Domain\Onboarding\Support\OnboardingCatalog;
use App\Domain\Tenant\Support\TenantSlug;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenantFromMembership;
use App\Http\Requests\Onboarding\StoreBusinessRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Self-service business setup wizard (Phase 2).
 */
class OnboardingController extends Controller
{
    public function create(Request $request, OnboardingCatalog $catalog): Response
    {
        return Inertia::render('onboarding/Create', [
            'catalog' => $catalog->toArray(),
            'limitReached' => OnboardBusiness::limitReached($request->user()),
            'hasWorkspaces' => $request->user()->accessibleTenants()->exists(),
            'defaults' => ['email' => $request->user()->email],
        ]);
    }

    public function slug(Request $request): JsonResponse
    {
        $slug = Str::lower(trim((string) $request->query('slug')));
        $name = Str::limit((string) $request->query('name'), 120, '');
        $valid = $slug !== '' && TenantSlug::isValid($slug);
        $available = $valid && TenantSlug::isAvailable($slug);
        $seed = $slug !== '' ? $slug : $name;

        return response()->json([
            'slug' => $slug,
            'valid' => $valid,
            'available' => $available,
            'suggestion' => $available || trim($seed) === '' ? null : TenantSlug::generate($seed),
            'domain' => $valid ? Hostname::subdomainFor($slug) : null,
        ]);
    }

    public function store(StoreBusinessRequest $request, OnboardingCatalog $catalog, OnboardBusiness $onboard): RedirectResponse
    {
        $user = $request->user();

        if (OnboardBusiness::limitReached($user)) {
            throw ValidationException::withMessages([
                'business' => __('You have reached the limit of :count businesses. Contact support to add more.', [
                    'count' => config('autowave.onboarding.max_businesses_per_user'),
                ]),
            ]);
        }

        $data = $request->validated();

        try {
            $tenant = $onboard->handle($user, $catalog->businessType($data['business_type']), [
                ...$data,
                'modules' => $data['modules'] ?? [],
            ]);
        } catch (InvalidArgumentException|UniqueConstraintViolationException $e) {
            // Someone claimed the slug between validation and insert.
            if (TenantSlug::isAvailable($data['slug'])) {
                throw $e;
            }

            throw ValidationException::withMessages(['slug' => __('This web address is already taken.')]);
        }

        $request->session()->put(ResolveTenantFromMembership::SESSION_KEY, $tenant->id);

        return redirect()->route('dashboard')->with('success', __(':name is ready. Welcome to AutoWave!', ['name' => $tenant->name]));
    }
}

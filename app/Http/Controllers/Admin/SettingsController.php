<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Platform\Support\PlatformSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Super Admin → Settings: platform-wide switches. Platform admins only (routes/admin.php). */
class SettingsController extends Controller
{
    public function index(PlatformSettings $settings): Response
    {
        return Inertia::render('admin/settings/Index', [
            'settings' => [
                'require_email_verification' => $settings->requireEmailVerification(),
            ],
            'unverifiedUsers' => User::query()->whereNull('email_verified_at')->count(),
        ]);
    }

    public function update(Request $request, PlatformSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'require_email_verification' => ['required', 'boolean'],
        ]);

        $required = (bool) $validated['require_email_verification'];

        if ($required !== $settings->requireEmailVerification()) {
            $settings->set(PlatformSettings::REQUIRE_EMAIL_VERIFICATION, $required);
            $audit->log('platform.settings_updated', metadata: ['require_email_verification' => $required]);
        }

        return back()->with('success', $required
            ? __('New accounts must confirm their email again.')
            : __('Email confirmation is off. New accounts can use the app straight away.'));
    }
}

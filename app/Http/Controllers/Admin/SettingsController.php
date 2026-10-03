<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Platform\Support\PlatformSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Super Admin → Settings: platform-wide switches and billing. Platform admins only (routes/admin.php). */
class SettingsController extends Controller
{
    public function index(PlatformSettings $settings, BillingSettings $billing, PaymentGateways $gateways): Response
    {
        $qr = $billing->qr();

        return Inertia::render('admin/settings/Index', [
            'settings' => [
                'require_email_verification' => $settings->requireEmailVerification(),
            ],
            'unverifiedUsers' => User::query()->whereNull('email_verified_at')->count(),
            'billing' => [
                ...Arr::except($billing->all(), ['qr', 'gst']),
                'gst' => Arr::only($billing->get('gst'), ['enabled', 'gstin']),
                'qr' => $qr ? ['updated_at' => $qr['updated_at'] ?? null, 'updated_by' => $qr['updated_by'] ?? null] : null,
                'gateway' => ['name' => $gateways->label(), 'configured' => $gateways->configured()],
                'gst_rate' => config('billing.gst.rate'),
                'qr_max_kb' => config('billing.qr.max_kb'),
            ],
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

    public function updateBilling(Request $request, UpdateBillingSettings $update): RedirectResponse
    {
        $update->update($request->only(['enforce', 'manual_enabled', 'online_enabled', 'seller', 'gst', 'upi', 'bank', 'instructions']), $request->user());

        return back()->with('success', __('Billing settings saved.'));
    }

    public function uploadQr(Request $request, UpdateBillingSettings $update): RedirectResponse
    {
        $update->uploadQr($request->file('qr'), $request->user());

        return back()->with('success', __('UPI QR code uploaded. Every platform admin was emailed about the change.'));
    }

    public function destroyQr(Request $request, UpdateBillingSettings $update): RedirectResponse
    {
        $update->deleteQr($request->user());

        return back()->with('success', __('UPI QR code removed.'));
    }

    public function qr(BillingSettings $billing): StreamedResponse
    {
        $qr = $billing->qr();
        abort_unless($qr && Storage::disk($qr['disk'])->exists($qr['path']), 404);

        return Storage::disk($qr['disk'])->response($qr['path'], null, [
            'Content-Type' => $qr['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

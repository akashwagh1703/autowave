<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Orders and shop: online ordering from the website, pickup and delivery. */
class CommerceSettingsController extends Controller
{
    public function show(CommerceSettings $settings): Response
    {
        return Inertia::render('business/settings/Commerce', [
            'online' => $settings->online(),
        ]);
    }

    public function update(Request $request, CommerceSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $max = config('commerce.limits.max_price');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_confirm' => ['required', 'boolean'],
            'pickup' => ['required', 'boolean'],
            'delivery' => ['required', 'boolean'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
            'free_delivery_over' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
            'min_order' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
            'delivery_note' => ['nullable', 'string', 'max:200'],
        ]);

        if ($validated['enabled'] && ! $validated['pickup'] && ! $validated['delivery']) {
            throw ValidationException::withMessages(['pickup' => __('Offer pickup, delivery or both when online ordering is on.')]);
        }

        $online = [
            'enabled' => (bool) $validated['enabled'],
            'auto_confirm' => (bool) $validated['auto_confirm'],
            'pickup' => (bool) $validated['pickup'],
            'delivery' => (bool) $validated['delivery'],
            'delivery_fee' => CommerceSettings::money($validated['delivery_fee'] ?? null) ?? '0.00',
            'free_delivery_over' => CommerceSettings::money($validated['free_delivery_over'] ?? null),
            'min_order' => CommerceSettings::money($validated['min_order'] ?? null),
            'delivery_note' => filled($validated['delivery_note'] ?? null) ? trim($validated['delivery_note']) : null,
        ];

        $settings->updateOnline($online);
        $audit->log('commerce.settings_updated', null, $online);

        return back()->with('success', __('Order settings saved.'));
    }
}

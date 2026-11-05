<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Support\QrCode;
use App\Domain\Commerce\Models\Product;
use App\Domain\Loyalty\Support\LoyaltyLinks;
use App\Domain\Loyalty\Support\LoyaltySettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Loyalty: stamp card rules and the join QR. */
class LoyaltySettingsController extends Controller
{
    public function show(LoyaltySettings $settings, TenantContext $context): Response
    {
        $config = $settings->all();
        $joinUrl = LoyaltyLinks::joinUrl();

        return Inertia::render('business/settings/Loyalty', [
            'loyalty' => $config,
            'join_url' => $joinUrl,
            'join_qr' => $joinUrl ? QrCode::pngDataUri($joinUrl, 280) : null,
            'products' => $context->hasEngine('commerce')
                ? Product::query()->orderBy('name')->get(['id', 'name', 'is_active'])->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'active' => $product->is_active,
                ])->all()
                : [],
            'has_booking' => $context->hasEngine('booking'),
            'has_commerce' => $context->hasEngine('commerce'),
        ]);
    }

    public function update(Request $request, LoyaltySettings $settings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'stamps_required' => ['required', 'integer', 'min:2', 'max:50'],
            'earn_per' => ['required', Rule::in(['order', 'item'])],
            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct'],
            'earn_appointments' => ['required', 'boolean'],
            'reward_label' => ['required', 'string', 'max:80'],
            'join_heading' => ['required', 'string', 'max:80'],
            'join_blurb' => ['required', 'string', 'max:300'],
            'collect_birthday' => ['required', 'boolean'],
            'birthday_mode' => ['required', Rule::in(['day', 'month'])],
            'birthday_grant_reward' => ['required', 'boolean'],
            'birthday_reward_label' => ['required', 'string', 'max:80'],
        ]);

        $productIds = array_values(array_unique(array_map('intval', $validated['product_ids'] ?? [])));

        if ($productIds !== []) {
            $valid = Product::query()->whereIn('id', $productIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $productIds = array_values(array_intersect($productIds, $valid));
        }

        $values = [
            'enabled' => (bool) $validated['enabled'],
            'stamps_required' => (int) $validated['stamps_required'],
            'earn_per' => $validated['earn_per'],
            'product_ids' => $productIds,
            'earn_appointments' => (bool) $validated['earn_appointments'],
            'reward_label' => trim($validated['reward_label']),
            'join_heading' => trim($validated['join_heading']),
            'join_blurb' => trim($validated['join_blurb']),
            'collect_birthday' => (bool) $validated['collect_birthday'],
            'birthday_mode' => $validated['birthday_mode'],
            'birthday_grant_reward' => (bool) $validated['birthday_grant_reward'],
            'birthday_reward_label' => trim($validated['birthday_reward_label']),
        ];

        $settings->update($values);
        $audit->log('loyalty.settings_updated', null, $values);

        return back()->with('success', __('Loyalty settings saved.'));
    }
}

<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Support\QrCode;
use App\Domain\Commerce\Support\CommerceSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Orders and shop: online ordering, pickup/delivery, and customer payment WhatsApp. */
class CommerceSettingsController extends Controller
{
    public function show(CommerceSettings $settings, TenantContext $context): Response
    {
        $confirmation = $settings->confirmation();
        $paymentQr = null;

        if (filled($confirmation['upi_id'] ?? null)) {
            $payee = $confirmation['upi_payee'] ?: $context->tenant()->name;
            $upi = 'upi://pay?'.http_build_query([
                'pa' => $confirmation['upi_id'],
                'pn' => $payee,
                'cu' => 'INR',
            ], '', '&', PHP_QUERY_RFC3986);
            $paymentQr = QrCode::pngDataUri($upi, 280);
        }

        return Inertia::render('business/settings/Commerce', [
            'online' => $settings->online(),
            'confirmation' => $confirmation,
            'payment_qr' => $paymentQr,
        ]);
    }

    public function update(Request $request, CommerceSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $max = config('commerce.limits.max_price');
        $messageMax = (int) config('automation.limits.message');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_confirm' => ['required', 'boolean'],
            'pickup' => ['required', 'boolean'],
            'delivery' => ['required', 'boolean'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
            'free_delivery_over' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
            'min_order' => ['nullable', 'numeric', 'min:0', 'max:'.$max],
            'delivery_note' => ['nullable', 'string', 'max:200'],
            'whatsapp' => ['required', 'boolean'],
            'include_qr' => ['required', 'boolean'],
            'qr_payload' => ['required', Rule::in(['status', 'reference', 'upi'])],
            'upi_id' => ['nullable', 'string', 'regex:'.UpdateBillingSettings::UPI_PATTERN],
            'upi_payee' => ['nullable', 'string', 'max:100'],
            'payment_message' => ['required', 'string', 'max:'.$messageMax],
            'message' => ['required', 'string', 'max:'.$messageMax],
            'ready_message_pickup' => ['required', 'string', 'max:'.$messageMax],
            'ready_message_delivery' => ['required', 'string', 'max:'.$messageMax],
            'ready_message' => ['required', 'string', 'max:'.$messageMax],
        ], [
            'upi_id.regex' => __('Enter a UPI ID like name@bank.'),
        ]);

        if ($validated['enabled'] && ! $validated['pickup'] && ! $validated['delivery']) {
            throw ValidationException::withMessages(['pickup' => __('Offer pickup, delivery or both when online ordering is on.')]);
        }

        if ($validated['whatsapp'] && $validated['include_qr'] && $validated['qr_payload'] === 'upi' && ! filled($validated['upi_id'] ?? null)) {
            throw ValidationException::withMessages(['upi_id' => __('Add a UPI ID when the QR is for payment.')]);
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

        $confirmation = [
            'whatsapp' => (bool) $validated['whatsapp'],
            'include_qr' => (bool) $validated['include_qr'],
            'qr_payload' => $validated['qr_payload'],
            'upi_id' => filled($validated['upi_id'] ?? null) ? trim($validated['upi_id']) : null,
            'upi_payee' => filled($validated['upi_payee'] ?? null) ? trim($validated['upi_payee']) : null,
            'payment_message' => trim($validated['payment_message']),
            'message' => trim($validated['message']),
            'ready_message_pickup' => trim($validated['ready_message_pickup']),
            'ready_message_delivery' => trim($validated['ready_message_delivery']),
            'ready_message' => trim($validated['ready_message']),
        ];

        $settings->update($online, $confirmation);
        $audit->log('commerce.settings_updated', null, ['online' => $online, 'confirmation' => $confirmation]);

        return back()->with('success', __('Order settings saved.'));
    }
}

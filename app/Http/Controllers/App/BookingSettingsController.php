<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Booking\Support\BookingSettings;
use App\Domain\Booking\Support\WeeklyHours;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BookingSettingsController extends Controller
{
    public function __construct(private readonly BookingSettings $settings) {}

    public function show(): Response
    {
        return Inertia::render('business/settings/Booking', [
            'settings' => [
                'slot_interval' => $this->settings->slotInterval(),
                'auto_confirm' => $this->settings->autoConfirm(),
                'resource_label' => $this->settings->resourceLabel(),
                'default_hours' => $this->settings->defaultHours(),
            ],
            'slotIntervals' => config('booking.slot_intervals'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['resource_label' => Str::squish((string) $request->input('resource_label'))]);

        $validated = $request->validate([
            'slot_interval' => ['required', 'integer', Rule::in(config('booking.slot_intervals'))],
            'auto_confirm' => ['required', 'boolean'],
            'resource_label' => ['required', 'string', 'min:2', 'max:40'],
            'default_hours' => ['present', 'array', 'max:28'],
        ]);

        $values = [
            'slot_interval' => (int) $validated['slot_interval'],
            'auto_confirm' => (bool) $validated['auto_confirm'],
            'default_hours' => WeeklyHours::normalize($validated['default_hours'], 'default_hours'),
        ];

        $this->settings->update($values, $validated['resource_label']);
        $audit->log('booking.settings_updated', null, [...$values, 'resource_label' => $validated['resource_label']]);

        return back()->with('success', __('Booking settings saved.'));
    }
}

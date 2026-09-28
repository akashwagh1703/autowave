<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Food\Support\FoodSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FoodSettingsController extends Controller
{
    public function __construct(private readonly FoodSettings $settings) {}

    public function show(): Response
    {
        return Inertia::render('business/settings/Food', [
            'settings' => $this->settings->reservations(),
            'durationOptions' => config('food.duration_options'),
            'slotIntervalOptions' => config('food.slot_interval_options'),
            'maxPartySizeLimit' => (int) config('food.max_party_size_limit'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'online' => ['required', 'boolean'],
            'auto_confirm' => ['required', 'boolean'],
            'duration_minutes' => ['required', 'integer', Rule::in(config('food.duration_options'))],
            'opens' => ['required', 'date_format:H:i'],
            'closes' => ['required', 'date_format:H:i'],
            'slot_interval' => ['required', 'integer', Rule::in(config('food.slot_interval_options'))],
            'max_party_size' => ['required', 'integer', 'min:1', 'max:'.config('food.max_party_size_limit')],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'max_days_ahead' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        if ($validated['closes'] <= $validated['opens']) {
            throw ValidationException::withMessages(['closes' => __('Closing time must be after opening time.')]);
        }

        $values = [
            'online' => (bool) $validated['online'],
            'auto_confirm' => (bool) $validated['auto_confirm'],
            'duration_minutes' => (int) $validated['duration_minutes'],
            'opens' => $validated['opens'],
            'closes' => $validated['closes'],
            'slot_interval' => (int) $validated['slot_interval'],
            'max_party_size' => (int) $validated['max_party_size'],
            'min_notice_minutes' => (int) $validated['min_notice_minutes'],
            'max_days_ahead' => (int) $validated['max_days_ahead'],
        ];

        $this->settings->updateReservations($values);
        $audit->log('food.settings_updated', null, $values);

        return back()->with('success', __('Reservation settings saved.'));
    }
}

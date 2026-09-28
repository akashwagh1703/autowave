<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Education\Support\EducationSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EducationSettingsController extends Controller
{
    public function __construct(private readonly EducationSettings $settings) {}

    public function show(): Response
    {
        return Inertia::render('business/settings/Education', [
            'settings' => $this->settings->all(),
            'reminderDaysOptions' => config('education.reminder_days_options'),
            'maxInstalments' => (int) config('education.max_instalments'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'default_instalments' => ['required', 'integer', 'min:1', 'max:'.config('education.max_instalments')],
            'reminder_days_before' => ['required', 'integer', Rule::in(config('education.reminder_days_options'))],
        ]);

        $values = array_map('intval', $validated);
        $this->settings->update($values);
        $audit->log('education.settings_updated', null, $values);

        return back()->with('success', __('Education settings saved.'));
    }
}

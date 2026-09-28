<?php

namespace App\Http\Controllers\App;

use App\Domain\AI\Models\AIUsage;
use App\Domain\AI\Services\AIGateway;
use App\Domain\AI\Support\AISettings;
use App\Domain\AI\Support\AIUsageMeter;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → AI: switch AI helpers on or off, the writing tone, notes for the AI, and this month's usage. */
class AiSettingsController extends Controller
{
    public function show(AISettings $settings, AIUsageMeter $meter, AIGateway $gateway, TenantContext $context): Response
    {
        $byFeature = AIUsage::query()
            ->where('created_at', '>=', AIUsageMeter::monthStart())
            ->selectRaw('feature, count(*) as requests, coalesce(sum(total_tokens), 0) as tokens')
            ->groupBy('feature')
            ->get()
            ->map(fn (AIUsage $row) => [
                'feature' => $row->feature,
                'label' => config("ai.features.{$row->feature}.label", $row->feature),
                'requests' => (int) $row->getAttribute('requests'),
                'tokens' => (int) $row->getAttribute('tokens'),
            ])
            ->sortByDesc('tokens')->values()->all();

        return Inertia::render('business/settings/Ai', [
            'settings' => $settings->all(),
            'status' => $gateway->status(),
            'usage' => [...$meter->summary($context->tenant()), 'features' => $byFeature],
            'tones' => collect(config('ai.tones'))->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'notesMax' => (int) config('ai.notes_max'),
            'leadsEnabled' => $context->hasModule('leads') && $context->hasModule('messaging'),
        ]);
    }

    public function update(Request $request, AISettings $settings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_extract' => ['required', 'boolean'],
            'tone' => ['required', 'string', Rule::in(array_keys(config('ai.tones')))],
            'notes' => ['nullable', 'string', 'max:'.config('ai.notes_max')],
        ]);

        $values = [
            'enabled' => (bool) $validated['enabled'],
            'auto_extract' => (bool) $validated['auto_extract'],
            'tone' => $validated['tone'],
            'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
        ];

        $settings->update($values);
        $audit->log('ai.settings_updated', null, [...$values, 'notes' => $values['notes'] !== null]);

        return back()->with('success', __('AI settings saved.'));
    }
}

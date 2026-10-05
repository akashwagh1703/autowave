<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Messaging\Actions\ConnectChannel;
use App\Domain\Messaging\Actions\DisconnectChannel;
use App\Domain\Messaging\Actions\SyncTemplates;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Support\MessagingSettings;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MessagingPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Messaging: connect WhatsApp and Instagram (manual, ADR-018), sync templates, quiet hours,
 * email sender. Tokens and app secrets are write-only: the page only learns whether one is saved.
 */
class MessagingSettingsController extends Controller
{
    public function show(Request $request, MessagingSettings $settings): Response
    {
        $canUpdate = $request->user()->can('settings.update');

        return Inertia::render('business/settings/Messaging', [
            'channels' => [
                'whatsapp' => MessagingPresenter::channel(ConnectChannel::ensure('whatsapp'), $canUpdate),
                'instagram' => MessagingPresenter::channel(ConnectChannel::ensure('instagram'), $canUpdate),
            ],
            'templates' => MessageTemplate::query()->where('channel', 'whatsapp')->orderBy('name')->orderBy('language')->get()
                ->map(fn (MessageTemplate $template) => MessagingPresenter::template($template))->all(),
            'quietHours' => $settings->quietHours(),
            'email' => $settings->email(),
            'ownerAlerts' => $settings->ownerAlerts(),
            'graphVersion' => config('messaging.meta.graph_version'),
        ]);
    }

    public function connectWhatsApp(Request $request, ConnectChannel $connect): RedirectResponse
    {
        $validated = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,32}$/'],
            'business_account_id' => ['required', 'string', 'regex:/^\d{5,32}$/'],
            'access_token' => ['nullable', 'string', 'max:1024'],
            'app_secret' => ['nullable', 'string', 'max:255'],
        ], [
            'phone_number_id.regex' => __('The phone number id is the long number shown under WhatsApp → API setup.'),
            'business_account_id.regex' => __('The WhatsApp Business Account id is a long number.'),
        ]);

        $connect->whatsapp($validated, $request->user());

        return back()->with('success', __('WhatsApp connected. Messages now go out from your number.'));
    }

    public function connectInstagram(Request $request, ConnectChannel $connect): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'string', 'regex:/^\d{5,64}$/'],
            'access_token' => ['nullable', 'string', 'max:1024'],
            'app_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $connect->instagram($validated, $request->user());

        return back()->with('success', __('Instagram connected. New direct messages will appear in the inbox.'));
    }

    public function disconnect(Request $request, DisconnectChannel $disconnect): RedirectResponse
    {
        $validated = $request->validate(['channel' => ['required', Rule::in(['whatsapp', 'instagram'])]]);
        $disconnect->handle(ConnectChannel::ensure($validated['channel']));

        return back()->with('success', __(':channel disconnected.', ['channel' => config("messaging.channels.{$validated['channel']}.label")]));
    }

    public function syncTemplates(SyncTemplates $sync): RedirectResponse
    {
        $count = $sync->handle();

        return back()->with('success', trans_choice('{0} No templates found in your WhatsApp account.|{1} 1 template synced.|[2,*] :count templates synced.', $count, ['count' => $count]));
    }

    public function updatePreferences(Request $request, MessagingSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'quiet_hours.enabled' => ['required', 'boolean'],
            'quiet_hours.start' => ['required', 'date_format:H:i'],
            'quiet_hours.end' => ['required', 'date_format:H:i', 'different:quiet_hours.start'],
            'email.from_name' => ['nullable', 'string', 'max:100', 'not_regex:/[\r\n<>"]/'],
            'email.reply_to' => ['nullable', 'email', 'max:191'],
            'owner_alerts' => ['sometimes', 'boolean'],
        ], [
            'quiet_hours.end.different' => __('Quiet hours must end at a different time than they start.'),
        ]);

        $quietHours = [
            'enabled' => (bool) $validated['quiet_hours']['enabled'],
            'start' => $validated['quiet_hours']['start'],
            'end' => $validated['quiet_hours']['end'],
        ];
        $email = [
            'from_name' => filled($validated['email']['from_name'] ?? null) ? trim($validated['email']['from_name']) : null,
            'reply_to' => filled($validated['email']['reply_to'] ?? null) ? trim($validated['email']['reply_to']) : null,
        ];

        $ownerAlerts = array_key_exists('owner_alerts', $validated) ? (bool) $validated['owner_alerts'] : $settings->ownerAlerts();

        $settings->update($quietHours, $email, $ownerAlerts);
        $audit->log('messaging.settings_updated', null, ['quiet_hours' => $quietHours, 'email' => $email, 'owner_alerts' => $ownerAlerts]);

        return back()->with('success', __('Messaging settings saved.'));
    }
}

<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Automation\Models\Automation;
use App\Domain\Chatbot\Services\ChatbotContent;
use App\Domain\Chatbot\Services\ChatbotEngine;
use App\Domain\Chatbot\Support\ChatbotSettings;
use App\Domain\Messaging\Support\ChannelResolver;
use App\Domain\Messaging\Support\MessagingSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → WhatsApp assistant (ADR-021): on/off, welcome, menu items, pause after a person takes over. */
class ChatbotSettingsController extends Controller
{
    public function show(ChatbotSettings $settings, ChatbotEngine $engine, ChatbotContent $content, ChannelResolver $channels, MessagingSettings $messaging, TenantContext $context): Response
    {
        $availability = $content->availability();
        $image = $content->welcomeImage();

        $items = collect(config('chatbot.items'))
            ->filter(fn (string $item) => $this->relevant($item, $context))
            ->map(fn (string $item) => [
                'item' => $item,
                ...$engine->label($item),
                'available' => $availability[$item],
                'hint' => $availability[$item] ? null : $this->hint($item),
            ])
            ->values()
            ->all();

        return Inertia::render('business/settings/WhatsAppAssistant', [
            'settings' => $settings->all(),
            'items' => $items,
            'business' => $content->name(),
            'defaultWelcome' => ChatbotEngine::defaultWelcome(),
            'image' => $image?->url(),
            'connected' => $channels->connected('whatsapp') !== null,
            'ownerAlerts' => $messaging->ownerAlerts(),
            'overlapping' => $this->overlappingAutomations($context),
            'limits' => ['welcome' => (int) config('chatbot.welcome_max'), ...config('chatbot.pause_hours')],
        ]);
    }

    public function update(Request $request, ChatbotSettings $settings, AuditLogger $audit): RedirectResponse
    {
        $limits = config('chatbot.pause_hours');
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'welcome' => ['nullable', 'string', 'max:'.config('chatbot.welcome_max')],
            'show_image' => ['required', 'boolean'],
            'items' => ['present', 'array'],
            'items.*' => ['string', Rule::in(config('chatbot.items'))],
            'pause_hours' => ['required', 'integer', 'min:'.$limits['min'], 'max:'.$limits['max']],
            'alert_team' => ['required', 'boolean'],
        ]);

        $values = [
            'enabled' => (bool) $validated['enabled'],
            'welcome' => filled($validated['welcome'] ?? null) ? trim($validated['welcome']) : null,
            'show_image' => (bool) $validated['show_image'],
            // "Talk to a person" is always offered, so a contact can always reach the team.
            'items' => array_values(array_unique([...$validated['items'], 'human'])),
            'pause_hours' => (int) $validated['pause_hours'],
            'alert_team' => (bool) $validated['alert_team'],
        ];

        $settings->update($values);
        $audit->log('chatbot.settings_updated', null, [...$values, 'welcome' => $values['welcome'] !== null]);

        return back()->with('success', $values['enabled'] ? __('WhatsApp assistant saved. It answers the next message.') : __('WhatsApp assistant saved. It is off.'));
    }

    /**
     * Active automations that also send WhatsApp when someone writes in, so the contact would get two replies.
     *
     * @return list<array{id: int, name: string}>
     */
    private function overlappingAutomations(TenantContext $context): array
    {
        if (! $context->hasModule('automation')) {
            return [];
        }

        return Automation::query()->active()
            ->whereIn('trigger', ['lead.created', 'message.received'])
            ->whereHas('nodes', fn ($node) => $node->where('action', 'send_whatsapp'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Automation $automation) => ['id' => $automation->id, 'name' => $automation->name])
            ->all();
    }

    /** Items that make sense for this kind of business (a salon is never offered "Reserve a table"). */
    private function relevant(string $item, TenantContext $context): bool
    {
        return match ($item) {
            'book' => $context->hasEngine('booking'),
            'reserve' => $context->hasEngine('food'),
            'order' => $context->hasEngine('commerce'),
            'services' => $context->hasEngine('service'),
            'rates' => $context->hasEngine('booking') && ! $context->hasEngine('service'),
            'courses' => $context->hasEngine('education'),
            'offers', 'faq' => $context->hasModule('website'),
            default => true,
        };
    }

    private function hint(string $item): string
    {
        return match ($item) {
            'book' => __('Turn on online booking in Booking settings, with at least one bookable team member or resource.'),
            'reserve' => __('Turn on online reservations in Reservation settings and show the Reservation section on your website.'),
            'order' => __('Turn on online ordering in Order settings, show the Products section and add products.'),
            'services' => __('Add at least one active service.'),
            'rates' => __('Add an hourly rate to at least one bookable resource.'),
            'courses' => __('Add at least one active course.'),
            'offers' => __('Add offers in your website’s Offers section and keep it switched on.'),
            'faq' => __('Add questions in your website’s FAQ section and keep it switched on.'),
            default => '',
        };
    }
}

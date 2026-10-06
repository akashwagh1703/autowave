<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Billing\Support\BillingRecipients;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Support\MessagingSettings;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Notifications\WebsiteActivityAlert;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Emails the owners when the WhatsApp assistant hands a chat to the team (the contact asked for a person,
 * sent a question, or was not understood). Off with Settings → Messaging → Email alerts. A failure is
 * reported but never stops the reply.
 */
class AlertTeamAboutChat
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MessagingSettings $settings,
    ) {}

    public function handle(Conversation $conversation, string $intro, ?string $message = null, ?string $interest = null): void
    {
        try {
            $tenant = $this->context->tenant();

            if (! $this->settings->ownerAlerts()) {
                return;
            }

            $owners = BillingRecipients::owners($tenant);

            if ($owners->isEmpty()) {
                return;
            }

            $name = $conversation->displayName();

            Notification::send($owners, new WebsiteActivityAlert(
                'whatsapp_handover',
                __(':name is waiting for a reply on WhatsApp', ['name' => $name]),
                $intro,
                array_filter([
                    __('Contact') => $name,
                    __('Phone') => $conversation->channel === 'whatsapp' ? $conversation->contact_handle : null,
                    __('Interested in') => $interest,
                    __('Message') => $message,
                ], fn ($value) => filled($value)),
                __('Open the chat'),
                rtrim((string) config('app.url'), '/')."/inbox/{$conversation->id}",
                $tenant->name,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Emails the owners about something the contact did in the chat (a demo class booked). Bookings,
     * reservations and orders are emailed by EmailOwnersAboutWebsiteActivity like website ones.
     *
     * @param  array<string, ?string>  $details
     */
    public function notify(Conversation $conversation, string $subject, string $intro, array $details, string $action, string $path, string $kind): void
    {
        try {
            $tenant = $this->context->tenant();
            $owners = $this->settings->ownerAlerts() ? BillingRecipients::owners($tenant) : collect();

            if ($owners->isEmpty()) {
                return;
            }

            Notification::send($owners, new WebsiteActivityAlert(
                'whatsapp_'.$kind,
                $subject,
                $intro,
                array_filter([
                    ...$details,
                    __('Phone') => $conversation->channel === 'whatsapp' ? $conversation->contact_handle : null,
                ], fn ($value) => filled($value)),
                $action,
                rtrim((string) config('app.url'), '/').$path,
                $tenant->name,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

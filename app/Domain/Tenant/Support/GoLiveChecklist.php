<?php

namespace App\Domain\Tenant\Support;

use App\Domain\Booking\Models\BookingResource;
use App\Domain\Messaging\Enums\ChannelStatus;
use App\Domain\Messaging\Models\MessagingChannel;
use App\Domain\Service\Models\Service;
use App\Domain\Website\Models\WebsiteConfig;

/**
 * Day-1 setup steps for the dashboard so owners finish the loops that already exist
 * (staff/hours, catalogue, publish website, connect WhatsApp).
 */
class GoLiveChecklist
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<array{key: string, label: string, done: bool, href: ?string}>
     */
    public function items(): array
    {
        $items = [];

        if ($this->context->hasEngine('booking')) {
            $resource = BookingResource::query()->active()->withCount('workingHours')->first();
            $items[] = [
                'key' => 'resource',
                'label' => 'Add team or resources with working hours',
                'done' => $resource !== null && $resource->working_hours_count > 0,
                'href' => '/resources',
            ];
        }

        if ($this->context->hasEngine('service')) {
            $items[] = [
                'key' => 'service',
                'label' => 'Add at least one active service or package',
                'done' => Service::query()->active()->exists(),
                'href' => '/services',
            ];
        }

        if ($this->context->hasModule('website')) {
            $config = WebsiteConfig::query()->first();
            $items[] = [
                'key' => 'website',
                'label' => 'Publish your website',
                'done' => $config?->isPublished() ?? false,
                'href' => '/website',
            ];
        }

        if ($this->context->hasModule('messaging')) {
            $items[] = [
                'key' => 'whatsapp',
                'label' => 'Connect WhatsApp in Settings → Messaging',
                'done' => MessagingChannel::query()->where('channel', 'whatsapp')->where('status', ChannelStatus::Connected)->exists(),
                'href' => '/settings/messaging',
            ];
        }

        return $items;
    }
}

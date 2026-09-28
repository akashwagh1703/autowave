<?php

namespace App\Domain\Messaging\Support;

use App\Domain\Tenant\Support\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** The tenant's quiet hours: when automation messages must wait (ADR-018). May cross midnight. */
class QuietHours
{
    public function __construct(
        private readonly MessagingSettings $settings,
        private readonly TenantContext $context,
    ) {}

    /** The moment quiet hours end, when `$at` falls inside them for this channel; null otherwise. UTC. */
    public function delayUntil(string $channel, ?DateTimeInterface $at = null): ?CarbonImmutable
    {
        $quiet = $this->settings->quietHours();

        if (! $quiet['enabled'] || $quiet['start'] === $quiet['end'] || ! in_array($channel, config('messaging.quiet_hours.channels', []), true)) {
            return null;
        }

        $timezone = $this->context->get()?->timezone ?? config('app.timezone');
        $local = CarbonImmutable::instance($at ?? now())->setTimezone($timezone);
        $start = $local->setTimeFromTimeString($quiet['start']);
        $end = $local->setTimeFromTimeString($quiet['end']);

        if ($start < $end) {
            // Same-day window, e.g. 13:00–15:00.
            return $local >= $start && $local < $end ? $end->utc() : null;
        }

        // Overnight window, e.g. 21:00–09:00.
        if ($local >= $start) {
            return $end->addDay()->utc();
        }

        return $local < $end ? $end->utc() : null;
    }
}

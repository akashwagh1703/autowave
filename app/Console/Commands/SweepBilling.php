<?php

namespace App\Console\Commands;

use App\Domain\Billing\Actions\OnlineCheckout;
use App\Domain\Billing\Actions\SubscriptionLifecycle;
use App\Domain\Billing\Models\BillingPayment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\SubscriptionReminder;
use App\Domain\Billing\Support\BillingRecipients;
use App\Domain\Billing\Support\BillingSettings;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs hourly (routes/console.php). Access is worked out from the dates on every request, so this only
 * expires unfinished online checkouts, makes scheduled plan changes permanent and emails owners: the trial or plan ends in 7, 3 and 1 days,
 * it ended, the business became read-only, or was locked (the last two only while enforcement is on).
 * Each email is sent once per period (`subscriptions.reminders`, cleared when a period is paid).
 */
class SweepBilling extends Command
{
    protected $signature = 'billing:sweep';

    protected $description = 'Apply scheduled plan changes and send subscription reminder emails';

    public function handle(SubscriptionLifecycle $lifecycle, BillingSettings $settings, Entitlements $entitlements, OnlineCheckout $checkout): int
    {
        $now = now();
        $sent = 0;
        $settled = 0;
        $expired = $checkout->expireStale();

        Subscription::withoutTenantScope()->whereNotNull('ends_at')->orderBy('id')->each(function (Subscription $subscription) use ($lifecycle, $settings, $entitlements, $now, &$sent, &$settled) {
            try {
                if ($lifecycle->settle($subscription, $now)) {
                    $subscription->save();
                    $settled++;
                }

                $tenant = Tenant::query()->find($subscription->tenant_id);

                if (! $tenant || $tenant->is_internal || ! $tenant->isActive()) {
                    return;
                }

                $reminder = $this->due($subscription, $settings->enforced(), $now);

                if (! $reminder || BillingPayment::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', BillingPayment::PENDING)->exists()) {
                    return;
                }

                [$type, $keys, $days] = $reminder;
                $subscription->reminders = array_values(array_unique([...($subscription->reminders ?? []), ...$keys]));
                $subscription->save();
                $entitlements->forget($tenant);

                Notification::send(BillingRecipients::owners($tenant), new SubscriptionReminder(
                    $type,
                    $tenant->name,
                    $subscription->is_trial,
                    $subscription->ends_at->copy()->timezone('Asia/Kolkata')->format('j M Y'),
                    $days,
                ));
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->components->info("Billing sweep: {$settled} plan changes applied, {$sent} reminders sent, {$expired} unfinished checkouts expired.");

        return self::SUCCESS;
    }

    /**
     * The reminder to send now, if any: [type, keys to mark as sent, days left].
     *
     * @return array{0: string, 1: list<string>, 2: int}|null
     */
    private function due(Subscription $subscription, bool $enforced, Carbon $now): ?array
    {
        $sent = $subscription->reminders ?? [];
        $endsAt = $subscription->ends_at;
        $unsent = fn (string $key) => ! in_array($key, $sent, true);

        if ($now->lt($endsAt)) {
            $left = $now->diffInSeconds($endsAt) / 86400;
            $days = collect(config('billing.reminder_days'))->map(fn ($day) => (int) $day)->sort()->values();
            $day = $days->first(fn (int $day) => $left <= $day);

            if ($day === null || ! $unsent("ending_{$day}")) {
                return null;
            }

            // A short period skips the earlier reminders rather than sending them all at once.
            $keys = $days->filter(fn (int $other) => $other >= $day)->map(fn (int $other) => "ending_{$other}")->values()->all();

            return [SubscriptionReminder::ENDING, $keys, max(1, (int) ceil($left))];
        }

        $readOnlyAt = $endsAt->copy()->addDays((int) config('billing.grace_days'));
        $locksAt = $endsAt->copy()->addDays((int) config('billing.lock_after_days'));

        return match (true) {
            $enforced && $now->gte($locksAt) && $unsent('locked') => [SubscriptionReminder::LOCKED, ['ended', 'read_only', 'locked'], 0],
            $enforced && $now->gte($readOnlyAt) && $now->lt($locksAt) && $unsent('read_only') => [SubscriptionReminder::READ_ONLY, ['ended', 'read_only'], 0],
            $now->lt($readOnlyAt) && $unsent('ended') => [SubscriptionReminder::ENDED, ['ended'], 0],
            default => null,
        };
    }
}

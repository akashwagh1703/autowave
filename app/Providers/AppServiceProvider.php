<?php

namespace App\Providers;

use App\Domain\AI\Listeners\QueueLeadExtraction;
use App\Domain\Automation\Listeners\RetimeAppointmentWaits;
use App\Domain\Automation\Listeners\StartAutomations;
use App\Domain\Billing\Support\Entitlements;
use App\Domain\Booking\Events\AppointmentRescheduled;
use App\Domain\Chatbot\Listeners\QueueChatbotReply;
use App\Domain\Messaging\Events\ConversationMessageReceived;
use App\Domain\RBAC\Support\PermissionCatalog;
use App\Domain\RBAC\Support\PermissionResolver;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Listeners\EmailOwnersAboutWebsiteActivity;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: a fresh instance per request and per queued job, so tenant state never leaks.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(PermissionResolver::class);
        $this->app->scoped(Entitlements::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (PermissionCatalog::keys() as $permission) {
            Gate::define($permission, fn (User $user) => $this->app->make(PermissionResolver::class)->allows($user, $permission));
        }

        foreach (StartAutomations::EVENTS as $event) {
            Event::listen($event, StartAutomations::class);
        }
        foreach (EmailOwnersAboutWebsiteActivity::EVENTS as $event) {
            Event::listen($event, EmailOwnersAboutWebsiteActivity::class);
        }
        Event::listen(AppointmentRescheduled::class, RetimeAppointmentWaits::class);
        Event::listen(ConversationMessageReceived::class, QueueLeadExtraction::class);
        Event::listen(ConversationMessageReceived::class, QueueChatbotReply::class);

        // Numeric ids only, so a malformed URL is a 404 rather than a database error.
        Route::patterns([
            'lead' => '[0-9]+',
            'customer' => '[0-9]+',
            'service' => '[0-9]+',
            'category' => '[0-9]+',
            'bookingResource' => '[0-9]+',
            'timeOff' => '[0-9]+',
            'appointment' => '[0-9]+',
            'automation' => '[0-9]+',
            'run' => '[0-9]+',
            'message' => '[0-9]+',
            'section' => '[0-9]+',
            'media' => '[0-9]+',
            'product' => '[0-9]+',
            'productCategory' => '[0-9]+',
            'order' => '[0-9]+',
            'payment' => '[0-9]+',
            'conversation' => '[0-9]+',
            'draft' => '[0-9]+',
            'billingPayment' => '[0-9]+',
            'plan' => '[0-9]+',
            'invoice' => '[0-9]+',
        ]);

        // Business creation attempts (including validation failures) per user.
        RateLimiter::for('onboarding', fn (Request $request) => Limit::perHour(20)->by('onboarding|'.($request->user()?->getKey() ?? $request->ip())));

        // Public website endpoints, per visitor IP and website host. Throttling runs before the tenant
        // is resolved (middleware priority), so the host stands in for the business.
        $site = fn (string $name, Request $request) => $name.'|'.$request->getHost().'|'.$request->ip();
        RateLimiter::for('website-enquiry', fn (Request $request) => [
            Limit::perMinute((int) config('website.enquiry.per_minute'))->by($site('enquiry-minute', $request)),
            Limit::perHour((int) config('website.enquiry.per_hour'))->by($site('enquiry-hour', $request)),
        ]);
        RateLimiter::for('website-booking', fn (Request $request) => Limit::perHour((int) config('booking.online_per_hour'))->by($site('booking', $request)));
        RateLimiter::for('website-slots', fn (Request $request) => Limit::perMinute(60)->by($site('slots', $request)));
        RateLimiter::for('website-cart', fn (Request $request) => Limit::perMinute(60)->by($site('cart', $request)));
        RateLimiter::for('website-order', fn (Request $request) => Limit::perHour((int) config('commerce.online_per_hour'))->by($site('order', $request)));
        RateLimiter::for('website-reservation', fn (Request $request) => Limit::perHour((int) config('food.online_per_hour'))->by($site('reservation', $request)));

        // Meta webhooks: per sending IP and webhook key (Meta sends from a pool of addresses).
        RateLimiter::for('meta-webhooks', fn (Request $request) => Limit::perMinute((int) config('messaging.webhooks.rate_limit'))
            ->by('meta-webhook|'.$request->ip().'|'.$request->route('webhookKey')));

        // AI requests per signed-in user (each one can cost money); the monthly cap is enforced separately.
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute((int) config('ai.limits.per_minute'))->by('ai|'.($request->user()?->getKey() ?? $request->ip())));

        // Manual payment submissions per user (each one emails every platform admin).
        RateLimiter::for('billing-pay', fn (Request $request) => Limit::perHour(5)->by('billing-pay|'.($request->user()?->getKey() ?? $request->ip())));
        RateLimiter::for('billing-checkout', fn (Request $request) => Limit::perHour(20)->by('billing-checkout|'.($request->user()?->getKey() ?? $request->ip())));
    }
}

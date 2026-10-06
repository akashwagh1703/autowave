<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Actions\ReceiveInboundMessage;
use App\Domain\Messaging\Inbound\InboundMessage;
use App\Domain\Messaging\Support\ContactHandle;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Local testing without Meta: feeds a message into the same pipeline a webhook uses (after
 * normalisation). Refuses to run in production.
 */
class SimulateInboundMessage extends Command
{
    protected $signature = 'messaging:simulate-inbound
        {tenant : Business slug, e.g. abc-salon}
        {from : Phone number (WhatsApp) or numeric user id (Instagram)}
        {text : Message text}
        {--channel=whatsapp : whatsapp or instagram}
        {--name= : Contact profile name}
        {--reply= : Option id of a tapped button or list row (the text is its title), e.g. aw.menu}';

    protected $description = 'Simulate an inbound WhatsApp or Instagram message (local development only)';

    public function handle(TenantContext $context, ReceiveInboundMessage $receive): int
    {
        if (app()->isProduction()) {
            $this->components->error('messaging:simulate-inbound does not run in production.');

            return self::FAILURE;
        }

        $channel = (string) $this->option('channel');
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        $handle = in_array($channel, ['whatsapp', 'instagram'], true) ? ContactHandle::for($channel, (string) $this->argument('from')) : null;

        if (! $tenant || ! $handle) {
            $this->components->error(! $tenant ? 'Unknown business slug.' : 'Invalid channel or sender.');

            return self::FAILURE;
        }

        $reply = $this->option('reply') ? Str::limit((string) $this->option('reply'), 200, '') : null;

        $message = $context->run($tenant, fn () => $receive->handle(new InboundMessage(
            channel: $channel,
            handle: $handle,
            providerMessageId: 'sim-in-'.Str::uuid(),
            type: $reply ? 'interactive' : 'text',
            text: Str::limit((string) $this->argument('text'), 4096, ''),
            occurredAt: CarbonImmutable::now('UTC'),
            name: $this->option('name') ?: null,
            meta: $reply ? ['reply_id' => $reply] : [],
        )));

        $this->components->info("Stored message #{$message?->id} in conversation #{$message?->conversation_id} for {$tenant->name}.");

        return self::SUCCESS;
    }
}

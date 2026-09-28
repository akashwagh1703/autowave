<?php

namespace Database\Seeders;

use App\Domain\Automation\Actions\SaveAutomation;
use App\Domain\Automation\Models\Automation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo: ABC Salon turns on its WhatsApp welcome and confirmation messages (simulated — the
 * log provider records them without sending) and adds one custom automation. Idempotent.
 */
class DemoAutomationSeeder extends Seeder
{
    public const TURN_ON = ['new_lead_welcome', 'appointment_confirmation'];

    public function run(TenantContext $context, SaveAutomation $save): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoAutomationSeeder must not run in production.');
        }

        $salon = Tenant::query()->where('slug', 'abc-salon')->first();

        if (! $salon) {
            return;
        }

        $context->run($salon, function () use ($save) {
            Automation::query()->whereIn('template_key', self::TURN_ON)->update(['is_active' => true]);

            if (Automation::withTrashed()->where('name', 'Tag big-ticket customers')->exists()) {
                return;
            }

            $save->handle([
                'name' => 'Tag big-ticket customers',
                'description' => 'Converted leads worth ₹5,000 or more are tagged VIP, and the owners are told.',
                'trigger' => 'lead.converted',
                'is_active' => true,
                'steps' => [
                    ['type' => 'condition', 'config' => ['match' => 'all', 'rules' => [['field' => 'lead.estimated_value', 'operator' => 'gte', 'value' => 5000]]]],
                    ['type' => 'action', 'action' => 'update_customer', 'config' => ['tag' => 'VIP']],
                    ['type' => 'action', 'action' => 'send_notification', 'config' => [
                        'recipients' => 'owners',
                        'subject' => 'New VIP customer: {{customer.name}}',
                        'message' => '{{lead.name}} converted with an estimated value over ₹5,000. They are tagged VIP.',
                    ]],
                ],
            ], actor: User::query()->where('email', 'owner@abc-salon.test')->first());
        });
    }
}

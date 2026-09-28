<?php

namespace Database\Seeders;

use App\Domain\Activity\Actions\LogActivity;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Lead\Actions\ChangeLeadStage;
use App\Domain\Lead\Actions\CreateLead;
use App\Domain\Lead\Models\Lead;
use App\Domain\Lead\Models\LeadSource;
use App\Domain\Lead\Models\LeadStage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantUser;
use App\Domain\Tenant\Support\TenantContext;
use App\Models\User;
use App\Support\TenantTime;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo CRM data for ABC Salon, created through the domain actions so the timeline,
 * customer links and conversions look real. Runs once per tenant (skips if leads exist).
 */
class DemoCrmSeeder extends Seeder
{
    public function run(TenantContext $context, CreateLead $createLead, CreateCustomer $createCustomer, ChangeLeadStage $changeStage, LogActivity $logActivity): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCrmSeeder must not run in production.');
        }

        $salon = Tenant::query()->where('slug', 'abc-salon')->first();

        if (! $salon) {
            return;
        }

        $context->run($salon, function (Tenant $tenant) use ($createLead, $createCustomer, $changeStage, $logActivity) {
            if (Lead::query()->exists()) {
                return;
            }

            $owner = User::query()->where('email', 'owner@abc-salon.test')->first();
            $manager = User::query()->where('email', 'manager@autowave.test')->first();
            $managerMembership = $manager ? TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $manager->id)->value('id') : null;
            $source = fn (string $code) => LeadSource::query()->where('code', $code)->value('id');
            $stage = fn (string $code) => LeadStage::query()->where('code', $code)->firstOrFail();
            $today = TenantTime::now()->setTime(11, 0);

            $createCustomer->handle(['name' => 'Kavya Rao', 'phone' => '98220 11111', 'email' => 'kavya@example.com', 'city' => 'Pune', 'tags' => ['VIP', 'Bridal']], $owner);
            $createCustomer->handle(['name' => 'Rohan Mehta', 'phone' => '98220 22222', 'city' => 'Pune', 'tags' => ['Regular']], $owner);

            $leads = [
                ['name' => 'Priya Sharma', 'phone' => '98765 10001', 'interest' => 'Bridal makeup package', 'estimated_value' => 25000, 'lead_source_id' => $source('instagram'), 'next_followup_at' => $today->copy()->utc(), 'assigned_tenant_user_id' => $managerMembership],
                ['name' => 'Neha Kulkarni', 'phone' => '98765 10002', 'interest' => 'Hair colour', 'estimated_value' => 3500, 'lead_source_id' => $source('whatsapp'), 'next_followup_at' => $today->copy()->subDay()->utc()],
                ['name' => 'Aditi Joshi', 'phone' => '98765 10003', 'email' => 'aditi@example.com', 'interest' => 'Monthly membership', 'estimated_value' => 6000, 'lead_source_id' => $source('website'), 'next_followup_at' => $today->copy()->addDays(2)->utc()],
                ['name' => 'Sneha Patil', 'phone' => '98765 10004', 'interest' => 'Facial', 'estimated_value' => 1800, 'lead_source_id' => $source('walk_in')],
                ['name' => 'Kavya Rao', 'phone' => '+91 98220 11111', 'interest' => 'Pre-bridal package', 'estimated_value' => 15000, 'lead_source_id' => $source('referral'), 'assigned_tenant_user_id' => $managerMembership],
                ['name' => 'Meera Iyer', 'phone' => '98765 10006', 'interest' => 'Keratin treatment', 'estimated_value' => 8000, 'lead_source_id' => $source('google')],
                ['name' => 'Anjali Desai', 'phone' => '98765 10007', 'interest' => 'Haircut', 'estimated_value' => 800, 'lead_source_id' => $source('qr')],
            ];

            $created = collect($leads)->map(fn (array $data) => $createLead->handle($data, $owner))->values();

            $logActivity->handle($created[0], 'call', 'Interested in the premium bridal package for a December wedding.', $owner, now()->subHours(3));
            $changeStage->handle($created[0], $stage('contacted'), $owner);
            $changeStage->handle($created[2], $stage('qualified'), $owner);
            $logActivity->handle($created[1], 'whatsapp', 'Shared the colour price list.', $owner, now()->subDay());
            $changeStage->handle($created[1], $stage('follow_up'), $owner);
            $changeStage->handle($created[5], $stage('converted'), $owner);
            $changeStage->handle($created[6], $stage('lost'), $owner, 'Chose a salon closer to home');
        });
    }
}

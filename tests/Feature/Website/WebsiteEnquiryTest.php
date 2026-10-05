<?php

namespace Tests\Feature\Website;

use App\Domain\Activity\Models\Activity;
use App\Domain\Lead\Models\Lead;
use App\Domain\Messaging\Support\MessagingSettings;
use App\Domain\Website\Actions\UpdateWebsiteSettings;
use App\Domain\Website\Models\WebsiteSection;
use App\Domain\Website\Notifications\WebsiteActivityAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAutomations;
use Tests\Concerns\CreatesCrmRecords;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WebsiteEnquiryTest extends TestCase
{
    use CreatesAutomations, CreatesCrmRecords, CreatesTenants, RefreshDatabase;

    private const SALON = 'abc-salon.autowave.test';

    private function enquire(array $data = [], string $host = self::SALON)
    {
        return $this->from($this->siteUrl($host))->post($this->siteUrl($host, '/enquiry'), [
            'name' => 'Asha Patil',
            'phone' => '98765 43210',
            'email' => 'asha@example.com',
            'interest' => 'Bridal makeup',
            'message' => 'Do you have a slot on 12 December?',
            ...$data,
        ]);
    }

    public function test_an_enquiry_creates_a_website_lead_and_starts_automations(): void
    {
        $tenant = $this->createTenant();
        $this->pauseDefaultAutomations($tenant);
        $automation = $this->makeAutomation($tenant, 'website.enquiry', [
            ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Call {{lead.name}} back', 'due_in_hours' => 1]],
        ]);

        $this->enquire()
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->siteUrl(self::SALON))
            ->assertSessionHas('enquiry_sent', true);

        $this->inTenant($tenant, function () {
            $lead = Lead::query()->with('source')->sole();
            $this->assertSame('Asha Patil', $lead->name);
            $this->assertSame('+919876543210', $lead->phone_normalized);
            $this->assertSame('website', $lead->source?->code);

            $activity = Activity::query()->where('lead_id', $lead->id)->where('type', 'website_enquiry')->sole();
            $this->assertSame('Do you have a slot on 12 December?', $activity->body);
            $this->assertSame('Bridal makeup', $activity->metadata['interest']);
            $this->assertNull($activity->user_id);
        });

        $run = $this->runOf($tenant, $automation);
        $this->assertSame('completed', $run->status->value);
        $this->assertSame('website.enquiry', $run->trigger);
        $this->assertTrue($run->payload['new_lead']);
    }

    public function test_the_owners_are_emailed_about_an_enquiry_unless_alerts_are_off(): void
    {
        Notification::fake();
        $tenant = $this->createTenant();
        $owner = $this->ownerOf($tenant);

        $this->enquire()->assertSessionHasNoErrors();

        $leadId = $this->inTenant($tenant, fn () => Lead::query()->sole()->id);
        Notification::assertSentTo($owner, WebsiteActivityAlert::class, function (WebsiteActivityAlert $alert) use ($owner, $leadId) {
            $mail = $alert->toMail($owner);

            return $mail->subject === 'New enquiry from Asha Patil'
                && in_array('Interested in: Bridal makeup', $mail->introLines, true)
                && in_array('Message: Do you have a slot on 12 December?', $mail->introLines, true)
                && $mail->actionUrl === rtrim(config('app.url'), '/')."/leads/{$leadId}";
        });

        $this->inTenant($tenant, function () {
            $settings = app(MessagingSettings::class);
            $settings->update($settings->quietHours(), $settings->email(), false);
        });
        $this->enquire(['phone' => '98989 89898'])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($owner, WebsiteActivityAlert::class, 1);
    }

    public function test_a_repeat_enquiry_is_added_to_the_open_lead(): void
    {
        $tenant = $this->createTenant();
        $lead = $this->makeLead($tenant, ['name' => 'Asha', 'phone' => '+91 98765 43210']);

        $this->enquire(['name' => 'Asha P', 'message' => 'Following up'])->assertSessionHasNoErrors();

        $this->inTenant($tenant, function () use ($lead) {
            $this->assertSame(1, Lead::query()->count());
            $activity = Activity::query()->where('lead_id', $lead->id)->where('type', 'website_enquiry')->sole();
            $this->assertSame('Following up', $activity->body);
            $this->assertSame('Asha P', $activity->metadata['name']);
        });
    }

    public function test_enquiries_are_validated(): void
    {
        $tenant = $this->createTenant();

        $this->enquire(['name' => '', 'phone' => 'call me'])->assertSessionHasErrors(['name', 'phone']);
        $this->enquire(['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->enquire(['message' => str_repeat('a', config('website.enquiry.max_message') + 1)])->assertSessionHasErrors('message');

        $this->assertSame(0, $this->inTenant($tenant, fn () => Lead::query()->count()));
    }

    public function test_bots_filling_the_honeypot_are_ignored_silently(): void
    {
        $tenant = $this->createTenant();

        $this->enquire(['company_website' => 'https://spam.example'])
            ->assertRedirect()
            ->assertSessionHas('enquiry_sent', true);

        $this->assertSame(0, $this->inTenant($tenant, fn () => Lead::query()->count()));
    }

    public function test_enquiries_are_rate_limited_per_visitor(): void
    {
        $this->createTenant();

        for ($i = 0; $i < config('website.enquiry.per_minute'); $i++) {
            $this->enquire()->assertSessionHasNoErrors();
        }

        $this->enquire()->assertStatus(429);

        // Inertia form posts get the message on the form instead of an error page.
        $this->withHeaders(['X-Inertia' => 'true'])->enquire()->assertRedirect()->assertSessionHasErrors('throttle');
    }

    public function test_the_form_only_accepts_enquiries_when_it_is_offered(): void
    {
        $tenant = $this->createTenant();

        $this->inTenant($tenant, fn () => WebsiteSection::query()->where('type', 'contact')->update(['configuration' => ['show_form' => false]]));
        $this->enquire()->assertNotFound();

        $this->inTenant($tenant, fn () => WebsiteSection::query()->where('type', 'contact')->update(['configuration' => ['show_form' => true]]));
        $this->enquire()->assertSessionHasNoErrors();

        $this->inTenant($tenant, fn () => app(UpdateWebsiteSettings::class)->publish(false));
        $this->enquire()->assertNotFound();
    }

    public function test_the_form_is_off_without_the_leads_module(): void
    {
        $tenant = $this->createTenant();
        $this->disableModule($tenant, 'leads');

        $this->enquire()->assertNotFound();
    }

    public function test_an_enquiry_only_reaches_the_business_of_the_website(): void
    {
        $salon = $this->createTenant();
        $coaching = $this->createTenant('Bright Coaching', 'coaching');

        $this->enquire([], 'bright-coaching.autowave.test')->assertSessionHasNoErrors();

        $this->assertSame(0, $this->inTenant($salon, fn () => Lead::query()->count()));
        $this->assertSame(1, $this->inTenant($coaching, fn () => Lead::query()->count()));
    }
}

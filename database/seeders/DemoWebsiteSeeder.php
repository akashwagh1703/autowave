<?php

namespace Database\Seeders;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteSection;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo: website content for ABC Salon and ABC Turf (contact details, opening hours, social
 * links, testimonials, offers and FAQs). Only fills values that are still empty, so edits made in
 * the website editor survive a re-run.
 */
class DemoWebsiteSeeder extends Seeder
{
    private const CONTENT = [
        'abc-salon' => [
            'profile' => [
                'whatsapp' => '+91 98765 43210',
                'email' => 'hello@abc-salon.test',
                'address' => 'Shop 4, Lane 7, Koregaon Park',
                'opening_hours' => "Tue–Sun: 10 am – 8 pm\nMonday: closed",
                'social' => ['instagram' => 'https://instagram.com/abcsalon.pune'],
            ],
            'sections' => [
                'hero' => ['headline' => 'Look good, feel great', 'cta' => 'book'],
                'testimonials' => ['items' => [
                    ['quote' => 'Best haircut I have had in Pune. Sana really listens.', 'author' => 'Priya K.', 'detail' => 'Regular since 2023'],
                    ['quote' => 'They made my wedding day stress-free. The bridal team is wonderful.', 'author' => 'Neha S.', 'detail' => 'Bridal client'],
                ]],
                'offers' => ['items' => [
                    ['title' => 'Weekday glow facial', 'description' => 'Tuesday to Thursday, 10 am – 4 pm.', 'price' => '20% off', 'valid_until' => '31 December'],
                ]],
                'faq' => ['items' => [
                    ['question' => 'Do I need an appointment?', 'answer' => 'Walk-ins are welcome, but booking online guarantees your slot.'],
                    ['question' => 'Is parking available?', 'answer' => 'Yes, free parking is available behind the building.'],
                ]],
            ],
        ],
        'abc-turf' => [
            'profile' => [
                'whatsapp' => '+91 91234 56789',
                'address' => 'Survey 42, Baner Road',
                'opening_hours' => 'Every day: 6 am – midnight',
                'description' => 'Two floodlit 5-a-side turfs with changing rooms and parking.',
            ],
            'sections' => [
                'hero' => ['headline' => 'Book your game in 30 seconds', 'cta' => 'book'],
                'faq' => ['items' => [
                    ['question' => 'How many players per side?', 'answer' => 'Our turfs are sized for 5-a-side, up to 7 players per team.'],
                    ['question' => 'Do you provide balls and bibs?', 'answer' => 'Yes, both are free with every booking.'],
                ]],
            ],
        ],
    ];

    public function run(TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoWebsiteSeeder must not run in production.');
        }

        foreach (self::CONTENT as $slug => $content) {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant) {
                continue;
            }

            $context->run($tenant, function () use ($context, $content) {
                $profile = $context->setting('business_profile', []);
                TenantSetting::query()->updateOrCreate(['key' => 'business_profile'], ['value' => self::fill($profile, $content['profile'])]);

                foreach ($content['sections'] as $type => $values) {
                    $section = WebsiteSection::query()->where('type', $type)->first();
                    $section?->update(['configuration' => self::fill($section->configuration ?? [], $values)]);
                }
            });
        }
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $demo
     * @return array<string, mixed>
     */
    private static function fill(array $current, array $demo): array
    {
        foreach ($demo as $key => $value) {
            if (blank($current[$key] ?? null)) {
                $current[$key] = $value;
            }
        }

        return $current;
    }
}

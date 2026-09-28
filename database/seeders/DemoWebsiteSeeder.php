<?php

namespace Database\Seeders;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Models\TenantSetting;
use App\Domain\Tenant\Support\TenantContext;
use App\Domain\Website\Models\WebsiteSection;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local demo: website content for the demo tenants (contact details, opening hours, social
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
        'abc-coaching' => [
            'profile' => [
                'whatsapp' => '+91 90000 11111',
                'address' => '2nd floor, Vidya Complex, Kothrud',
                'opening_hours' => "Mon–Sat: 7 am – 8 pm\nSunday: weekend batches only",
            ],
            'sections' => [
                'hero' => ['headline' => 'Better marks, less stress', 'cta' => 'call'],
                'faq' => ['items' => [
                    ['question' => 'Can my child attend a demo class?', 'answer' => 'Yes. Send us an enquiry and we will book a free demo class in the right batch.'],
                    ['question' => 'Can fees be paid in instalments?', 'answer' => 'Yes, most courses can be paid in two to four instalments.'],
                ]],
            ],
        ],
        'abc-cafe' => [
            'profile' => [
                'whatsapp' => '+91 90000 22222',
                'address' => 'Lane 5, Prabhat Road',
                'opening_hours' => 'Every day: 8 am – 11 pm',
            ],
            'sections' => [
                'hero' => ['headline' => 'Great coffee, all-day breakfast', 'cta' => 'book'],
                'offers' => ['items' => [
                    ['title' => 'WELCOME10', 'description' => '10% off your first online order (up to ₹150).', 'price' => '10% off'],
                ]],
            ],
        ],
        'abc-store' => [
            'profile' => [
                'whatsapp' => '+91 90000 33333',
                'address' => 'Shop 1, Green Park Society, Aundh',
                'opening_hours' => 'Every day: 7 am – 10 pm',
            ],
            'sections' => [
                'hero' => ['headline' => 'Your daily groceries, delivered', 'cta' => 'whatsapp'],
                'offers' => ['items' => [
                    ['title' => 'SAVE50', 'description' => '₹50 off orders over ₹999. Use the code at checkout.', 'price' => '₹50 off'],
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

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
                'phone' => '+91 75584 30055',
                'whatsapp' => '+91 75584 30055',
                'email' => 'hello@abc-turf.test',
                'city' => 'Nashik',
                'address' => 'STP Road, Pushpak Nagar, Lokhande Mala, Nashik Road, Nashik, Maharashtra 422006',
                'opening_hours' => 'Open 24 hours, every day',
                'description' => 'We provide cricket and football turf, plus pickleball and a cafe with quality food. Floodlights, parking and a recreational hub near Sandip University, Nashik Road.',
                'social' => ['instagram' => 'https://instagram.com/saffron.arena'],
            ],
            'sections' => [
                'hero' => [
                    'headline' => 'Book your slot at Saffron Arena',
                    'subheadline' => 'Cricket, football and more — open 24 hours on Nashik Road',
                    'cta' => 'book',
                ],
                'about' => [
                    'headline' => 'Play hard. Stay longer.',
                    'body' => 'Saffron Arena is a multisport turf in Pushpak Nagar, Nashik Road — cricket and football pitches, pickleball, ample parking and Saffron Sip cafe on site.',
                ],
                'testimonials' => ['items' => [
                    ['quote' => 'One thing I really liked is the positive and energetic atmosphere here.', 'author' => 'Google review', 'detail' => '5.0 · Saffron Arena'],
                    ['quote' => 'Definitely a great place for sports lovers.', 'author' => 'Google review', 'detail' => 'Nashik'],
                    ['quote' => 'Ample space for parking.', 'author' => 'Google review', 'detail' => 'Nashik Road'],
                ]],
                'faq' => ['items' => [
                    ['question' => 'What sports can we play?', 'answer' => 'Cricket and football turfs are available, plus pickleball. Ask us when you book if you need a specific pitch.'],
                    ['question' => 'Are you open late?', 'answer' => 'Yes — we are open 24 hours, every day, with floodlights for night games.'],
                    ['question' => 'Is parking available?', 'answer' => 'Yes, there is ample parking on site.'],
                    ['question' => 'Is there food on site?', 'answer' => 'Yes — Saffron Sip cafe serves food and drinks at the venue.'],
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

            $context->run($tenant, function () use ($context, $content, $slug) {
                $profile = $context->setting('business_profile', []);
                $profile = is_array($profile) ? $profile : [];
                // Turf demo tracks a real Nashik listing — always refresh those fields on re-seed.
                $mergedProfile = $slug === 'abc-turf'
                    ? [...$profile, ...$content['profile']]
                    : self::fill($profile, $content['profile']);
                TenantSetting::query()->updateOrCreate(['key' => 'business_profile'], ['value' => $mergedProfile]);

                foreach ($content['sections'] as $type => $values) {
                    $section = WebsiteSection::query()->where('type', $type)->first();
                    if (! $section) {
                        continue;
                    }
                    $config = $section->configuration ?? [];
                    $section->update([
                        'configuration' => $slug === 'abc-turf'
                            ? self::replaceDemo($config, $values)
                            : self::fill($config, $values),
                    ]);
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

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $demo
     * @return array<string, mixed>
     */
    private static function replaceDemo(array $current, array $demo): array
    {
        foreach ($demo as $key => $value) {
            $current[$key] = $value;
        }

        return $current;
    }
}

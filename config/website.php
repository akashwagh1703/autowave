<?php

/*
|--------------------------------------------------------------------------
| Website engine (Phase 6, ADR-012, ADR-016)
|--------------------------------------------------------------------------
|
| Section types a tenant website can contain. Each type declares:
| - label / description: shown in the website editor;
| - fields: the section's own copy, edited in the business app and validated by
|   SectionSchema. Field types: text, textarea, boolean, select, list (repeatable
|   items with their own `fields`). `max` is a character limit (or item count for
|   lists); `default` fills fields the tenant has not set; `ai: true` offers
|   "Write with AI" on the field (Phase 9, ADR-019);
| - data: business records the section displays (services, team, gallery ...).
|   Sections with a data source are hidden on the site while it has no records;
| - media: a media collection managed on the section's edit page;
| - engine / module: required for the section to be offered and rendered;
| - position: first|last pins the header and footer; removable: false keeps them.
|
| Business data (names, prices, phone numbers) is never copied into sections.
|
*/

return [

    'sections' => [
        'header' => [
            'label' => 'Header',
            'description' => 'Logo, business name and the main buttons.',
            'position' => 'first',
            'removable' => false,
            'fields' => [
                'show_call' => ['type' => 'boolean', 'label' => 'Show a "Call us" button', 'default' => true],
                'show_book' => ['type' => 'boolean', 'label' => 'Show a "Book now" button', 'default' => true, 'engine' => 'booking'],
            ],
        ],
        'hero' => [
            'label' => 'Hero banner',
            'description' => 'The large banner visitors see first.',
            'media' => 'hero',
            'fields' => [
                'headline' => ['type' => 'text', 'label' => 'Headline', 'max' => 120, 'ai' => true, 'help' => 'Leave empty to show the business name.'],
                'subheadline' => ['type' => 'textarea', 'label' => 'Subheadline', 'max' => 300, 'ai' => true, 'help' => 'Leave empty to show the tagline.'],
                'cta' => ['type' => 'select', 'label' => 'Main button', 'default' => 'contact', 'options' => [
                    'book' => 'Book now',
                    'contact' => 'Contact us',
                    'whatsapp' => 'Chat on WhatsApp',
                    'call' => 'Call us',
                ]],
            ],
        ],
        'about' => [
            'label' => 'About',
            'description' => 'Your story in a few lines.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'About us'],
                'body' => ['type' => 'textarea', 'label' => 'Text', 'max' => 2000, 'rows' => 6, 'ai' => true, 'help' => 'Leave empty to use the business description.'],
            ],
        ],
        'services' => [
            'label' => 'Services',
            'description' => 'Your active services with prices, straight from Services.',
            'engine' => 'service',
            'data' => 'services',
            'empty_hint' => 'Add active services to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Our services'],
                'intro' => ['type' => 'textarea', 'label' => 'Introduction', 'max' => 300, 'ai' => true],
                'show_prices' => ['type' => 'boolean', 'label' => 'Show prices', 'default' => true],
                'show_duration' => ['type' => 'boolean', 'label' => 'Show durations', 'default' => true],
            ],
        ],
        'products' => [
            'label' => 'Products',
            'description' => 'Your active products, straight from Products. Visitors can order them when online ordering is on.',
            'engine' => 'commerce',
            'data' => 'products',
            'empty_hint' => 'Add active products to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Shop our products'],
                'intro' => ['type' => 'textarea', 'label' => 'Introduction', 'max' => 300, 'ai' => true],
                'show_prices' => ['type' => 'boolean', 'label' => 'Show prices', 'default' => true, 'help' => 'Prices are always shown in the cart.'],
            ],
        ],
        'courses' => [
            'label' => 'Courses',
            'description' => 'Your active courses and batches with timings, straight from Courses.',
            'engine' => 'education',
            'data' => 'courses',
            'empty_hint' => 'Add active courses to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Our courses'],
                'intro' => ['type' => 'textarea', 'label' => 'Introduction', 'max' => 300, 'ai' => true],
                'show_fees' => ['type' => 'boolean', 'label' => 'Show fees', 'default' => true],
                'show_batches' => ['type' => 'boolean', 'label' => 'Show batch timings', 'default' => true],
                'show_enquire' => ['type' => 'boolean', 'label' => 'Show an "Enquire / book a demo" button', 'default' => true, 'module' => 'leads'],
            ],
        ],
        'reservation' => [
            'label' => 'Table reservation',
            'description' => 'Visitors request a table for a date, time and party size. Uses Settings → Reservations.',
            'engine' => 'food',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Reserve a table'],
                'intro' => ['type' => 'textarea', 'label' => 'Introduction', 'max' => 300, 'ai' => true],
                'success_message' => ['type' => 'text', 'label' => 'Message after the request', 'max' => 200, 'default' => 'Thank you! We have received your reservation request.'],
            ],
        ],
        'packages' => [
            'label' => 'Packages',
            'description' => 'Bundles of services and products.',
            'engine' => 'service',
            'data' => 'packages',
            'empty_hint' => 'Packages are not available yet; this section stays hidden until they are.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Packages'],
                'intro' => ['type' => 'textarea', 'label' => 'Introduction', 'max' => 300, 'ai' => true],
            ],
        ],
        'gallery' => [
            'label' => 'Gallery',
            'description' => 'Photos of your work and your place.',
            'data' => 'gallery',
            'media' => 'gallery',
            'empty_hint' => 'Upload photos to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Gallery'],
            ],
        ],
        'team' => [
            'label' => 'Team',
            'description' => 'Your staff or bookable resources, straight from the booking setup.',
            'engine' => 'booking',
            'data' => 'team',
            'empty_hint' => 'Add active staff or resources to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Meet the team'],
                'intro' => ['type' => 'textarea', 'label' => 'Introduction', 'max' => 300, 'ai' => true],
                'show_services' => ['type' => 'boolean', 'label' => 'Show what each one offers', 'default' => true, 'engine' => 'service'],
            ],
        ],
        'testimonials' => [
            'label' => 'Testimonials',
            'description' => 'Kind words from your customers.',
            'data' => 'items',
            'empty_hint' => 'Add at least one testimonial to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'What our customers say'],
                'items' => ['type' => 'list', 'label' => 'Testimonials', 'item_label' => 'Testimonial', 'max' => 12, 'fields' => [
                    'quote' => ['type' => 'textarea', 'label' => 'Quote', 'max' => 500, 'required' => true],
                    'author' => ['type' => 'text', 'label' => 'Name', 'max' => 80, 'required' => true],
                    'detail' => ['type' => 'text', 'label' => 'Detail', 'max' => 80, 'help' => 'For example "Bridal client".'],
                ]],
            ],
        ],
        'reviews' => [
            'label' => 'Reviews',
            'description' => 'Verified customer reviews.',
            'module' => 'reviews',
            'data' => 'reviews',
            'empty_hint' => 'Reviews appear here once the Reviews module is available.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Reviews'],
            ],
        ],
        'offers' => [
            'label' => 'Offers',
            'description' => 'Current deals and promotions.',
            'data' => 'items',
            'empty_hint' => 'Add at least one offer to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Offers'],
                'items' => ['type' => 'list', 'label' => 'Offers', 'item_label' => 'Offer', 'max' => 6, 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Title', 'max' => 80, 'required' => true],
                    'description' => ['type' => 'textarea', 'label' => 'Description', 'max' => 300, 'ai' => true],
                    'price' => ['type' => 'text', 'label' => 'Price or discount', 'max' => 40, 'help' => 'For example "₹999" or "20% off".'],
                    'valid_until' => ['type' => 'text', 'label' => 'Valid until', 'max' => 40, 'help' => 'For example "31 October".'],
                ]],
            ],
        ],
        'faq' => [
            'label' => 'FAQ',
            'description' => 'Answers to common questions.',
            'data' => 'items',
            'empty_hint' => 'Add at least one question to show this section.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Frequently asked questions'],
                'items' => ['type' => 'list', 'label' => 'Questions', 'item_label' => 'Question', 'max' => 20, 'fields' => [
                    'question' => ['type' => 'text', 'label' => 'Question', 'max' => 200, 'required' => true],
                    'answer' => ['type' => 'textarea', 'label' => 'Answer', 'max' => 1000, 'required' => true, 'ai' => true],
                ]],
            ],
        ],
        'contact' => [
            'label' => 'Contact',
            'description' => 'Phone, email, address, opening hours and the enquiry form.',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Get in touch'],
                'show_form' => ['type' => 'boolean', 'label' => 'Show the enquiry form', 'default' => true, 'module' => 'leads', 'help' => 'Enquiries become leads with the source "Website".'],
                'form_heading' => ['type' => 'text', 'label' => 'Form heading', 'max' => 80, 'default' => 'Send us a message', 'module' => 'leads'],
                'success_message' => ['type' => 'text', 'label' => 'Message after sending', 'max' => 200, 'default' => 'Thank you! We will get back to you shortly.', 'module' => 'leads'],
                'show_map' => ['type' => 'boolean', 'label' => 'Show a "Get directions" link', 'default' => true],
            ],
        ],
        'booking' => [
            'label' => 'Online booking',
            'description' => 'Visitors pick a service, a time and book. Uses your services, staff and working hours.',
            'engine' => 'booking',
            'fields' => [
                'heading' => ['type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => 'Book online'],
                'intro' => ['type' => 'textarea', 'label' => 'Introduction', 'max' => 300, 'ai' => true],
                'success_message' => ['type' => 'text', 'label' => 'Message after booking', 'max' => 200, 'default' => 'Thank you! Your booking request has been received.'],
            ],
        ],
        'footer' => [
            'label' => 'Footer',
            'description' => 'Copyright line and social links.',
            'position' => 'last',
            'removable' => false,
            'fields' => [
                'text' => ['type' => 'textarea', 'label' => 'Short text', 'max' => 300, 'ai' => true],
            ],
        ],
    ],

    /*
    | Uploaded images. Stored on `disk` under tenant/{tenant_id}/{path}/ (master prompt §61)
    | and served from /storage (run `php artisan storage:link`). SVG is not accepted.
    */
    'media' => [
        'disk' => env('WEBSITE_MEDIA_DISK', 'public'),
        'max_kb' => 4096,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'min_dimension' => 100,
        'max_dimension' => 6000,
        'collections' => [
            'logo' => ['label' => 'Logo', 'max' => 1, 'path' => 'logo'],
            'hero' => ['label' => 'Hero image', 'max' => 1, 'path' => 'website'],
            'gallery' => ['label' => 'Gallery', 'max' => 24, 'path' => 'website'],
            // One image per product (products.image_media_id); managed on the product page.
            'product' => ['label' => 'Product image', 'max' => 2000, 'path' => 'products', 'website' => false],
        ],
    ],

    // Social profile links shown in the footer (business_profile.social).
    'social' => [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'google' => 'Google Business profile',
    ],

    // Pre-filled text of the WhatsApp button. :business is replaced with the business name.
    'whatsapp_message' => 'Hi :business, I found you on your website and would like to know more.',

    'enquiry' => [
        'max_message' => 2000,
        // Per visitor IP and business.
        'per_minute' => 5,
        'per_hour' => 20,
    ],

    // Signed preview links for unpublished websites stay valid this long.
    'preview_minutes' => 60,

];

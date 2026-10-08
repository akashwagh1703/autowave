<?php

/*
|--------------------------------------------------------------------------
| Platform catalogue: engines, modules and business types
|--------------------------------------------------------------------------
|
| Source of truth synced into the database by CatalogSeeder (ADR-005, ADR-006).
| Configure, don't hard-code: a new vertical is a new business type here, not
| new application code.
|
| - engines.requires_modules: modules that must be enabled before the engine.
| - modules.depends_on: modules that must be enabled before the module.
| - business_types: versioned presets applied when a tenant is created.
|
*/

return [

    'engines' => [
        'service' => [
            'name' => 'Service Engine',
            'description' => 'Service catalogue, packages, pricing, durations, staff skills.',
            'requires_modules' => [],
        ],
        'booking' => [
            'name' => 'Booking Engine',
            'description' => 'Resources, availability, slots, appointments and reschedules.',
            'requires_modules' => ['customers'],
        ],
        'commerce' => [
            'name' => 'Commerce Engine',
            'description' => 'Products, variants, inventory, cart and orders.',
            'requires_modules' => ['customers', 'payments'],
        ],
        'education' => [
            'name' => 'Education Engine',
            'description' => 'Courses, batches, students, admissions, attendance and fees.',
            'requires_modules' => ['customers'],
        ],
        'food' => [
            'name' => 'Food Engine',
            'description' => 'Menu, tables, reservations and kitchen orders.',
            'requires_modules' => ['customers'],
        ],
    ],

    // Modules with status `draft` stay in the catalogue for future work but are not offered in
    // onboarding (OnboardingCatalog only lists active). Do not put draft modules on business-type presets.
    'modules' => [
        'customers' => ['name' => 'Customers', 'description' => 'Customer records and unified timeline.', 'depends_on' => []],
        'crm' => ['name' => 'CRM', 'description' => 'Relationship management, notes, tags and activities.', 'depends_on' => ['customers']],
        'leads' => ['name' => 'Lead Management', 'description' => 'Capture, assign and follow up leads.', 'depends_on' => ['customers']],
        'messaging' => ['name' => 'Messaging', 'description' => 'WhatsApp, Instagram and email conversations.', 'depends_on' => ['customers']],
        'marketing' => ['name' => 'Marketing', 'description' => 'Campaigns and audience targeting.', 'depends_on' => ['customers'], 'status' => 'draft'],
        'offers' => ['name' => 'Offers', 'description' => 'Discounts and promotional offers.', 'depends_on' => ['customers']],
        'automation' => ['name' => 'Automation', 'description' => 'Trigger, condition, wait and action workflows.', 'depends_on' => []],
        'website' => ['name' => 'Website', 'description' => 'Section-based public business website.', 'depends_on' => []],
        'forms' => ['name' => 'Forms', 'description' => 'Enquiry and lead capture forms.', 'depends_on' => ['leads'], 'status' => 'draft'],
        'qr' => ['name' => 'QR', 'description' => 'QR codes for lead capture and offers.', 'depends_on' => [], 'status' => 'draft'],
        'reviews' => ['name' => 'Reviews', 'description' => 'Collect and display customer reviews.', 'depends_on' => ['customers'], 'status' => 'draft'],
        'loyalty' => ['name' => 'Loyalty', 'description' => 'Points and rewards for repeat customers.', 'depends_on' => ['customers'], 'status' => 'draft'],
        'membership' => ['name' => 'Membership', 'description' => 'Memberships and subscriptions for customers.', 'depends_on' => ['customers'], 'status' => 'draft'],
        'payments' => ['name' => 'Payments', 'description' => 'Online and offline payment tracking.', 'depends_on' => []],
        'analytics' => ['name' => 'Analytics', 'description' => 'Business dashboards and reports.', 'depends_on' => [], 'status' => 'draft'],
        'inventory' => ['name' => 'Inventory', 'description' => 'Stock levels and movements.', 'depends_on' => []],
        'ai' => ['name' => 'AI Assistant', 'description' => 'Suggested replies, summaries, lead details and writing help.', 'depends_on' => []],
    ],

    /*
    | Website templates: look and feel only. Content always comes from tenant data,
    | so switching template never loses information (master prompt §23).
    | theme.hero: gradient|dark|plain|soft|solid · theme.font: sans|serif · theme.radius: none|sm|md|lg
    */
    'website_templates' => [
        'modern' => ['name' => 'Modern', 'description' => 'Bold gradient hero and rounded cards.', 'theme' => ['hero' => 'gradient', 'font' => 'sans', 'radius' => 'lg']],
        'premium' => ['name' => 'Premium', 'description' => 'Dark, high-contrast hero with serif headings.', 'theme' => ['hero' => 'dark', 'font' => 'serif', 'radius' => 'md']],
        'minimal' => ['name' => 'Minimal', 'description' => 'Clean white layout with sharp edges.', 'theme' => ['hero' => 'plain', 'font' => 'sans', 'radius' => 'none']],
        'elegant' => ['name' => 'Elegant', 'description' => 'Soft tinted hero with serif headings.', 'theme' => ['hero' => 'soft', 'font' => 'serif', 'radius' => 'lg']],
        'corporate' => ['name' => 'Corporate', 'description' => 'Solid brand-colour hero, straightforward layout.', 'theme' => ['hero' => 'solid', 'font' => 'sans', 'radius' => 'sm']],
    ],

    /*
    | Business types. `website_templates` lists recommended templates; the first is the default.
    | `configuration` keys other than website_*, lead_*, service_categories and automation_templates
    | are copied into tenant settings on creation.
    | `configuration.lead_stages` / `lead_sources` replace the config/crm.php defaults for that type.
    | `configuration.service_categories` are created for tenants with the service engine.
    | `configuration.automation_templates` picks keys from config/automation.php `templates`
    | (default: automation.default_templates).
    | `configuration.booking` overrides config/booking.php defaults (slot_interval, auto_confirm, default_hours).
    | `configuration.commerce.online` overrides config/commerce.php `online` defaults (pickup, delivery, fees…).
    */
    'business_types' => [
        'beauty_salon' => [
            'name' => 'Beauty & Salon',
            'description' => 'Salons, spas and beauty studios.',
            'icon' => 'spa',
            'version' => '1.0',
            'website_templates' => ['elegant', 'modern', 'premium'],
            'engines' => ['service', 'booking', 'commerce'],
            'modules' => ['crm', 'leads', 'customers', 'messaging', 'automation', 'website', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['revenue_today', 'appointments_today', 'new_leads', 'pending_followups', 'service_sales', 'product_sales', 'repeat_customers', 'potential_revenue'],
                'website_sections' => ['header', 'hero', 'about', 'services', 'packages', 'products', 'gallery', 'team', 'testimonials', 'offers', 'faq', 'contact', 'booking', 'footer'],
                'booking_resource_label' => 'Staff',
                'service_categories' => ['Hair', 'Skin', 'Nails', 'Makeup', 'Spa'],
            ],
        ],
        'photo_studio' => [
            'name' => 'Photo Studio',
            'description' => 'Photography studios for weddings, portraits, products and events.',
            'icon' => 'camera',
            'version' => '1.0',
            'website_templates' => ['premium', 'elegant', 'modern'],
            'engines' => ['service', 'booking', 'commerce'],
            'modules' => ['crm', 'leads', 'customers', 'messaging', 'offers', 'automation', 'website', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['revenue_today', 'appointments_today', 'new_leads', 'pending_followups', 'service_sales', 'product_sales', 'potential_revenue'],
                'website_sections' => ['header', 'hero', 'about', 'services', 'packages', 'products', 'gallery', 'team', 'testimonials', 'offers', 'faq', 'contact', 'booking', 'footer'],
                'booking_resource_label' => 'Photographer',
                'service_categories' => ['Wedding', 'Maternity', 'Portrait', 'Product', 'Events', 'Passport & ID'],
            ],
        ],
        'turf' => [
            'name' => 'Turf & Sports Venue',
            'description' => 'Turfs, courts and slot-based venues.',
            'icon' => 'sports',
            'version' => '1.0',
            'website_templates' => ['modern', 'corporate', 'minimal'],
            'engines' => ['booking'],
            'modules' => ['crm', 'leads', 'messaging', 'payments', 'automation', 'website', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['bookings_today', 'available_slots', 'revenue_today', 'cancellations', 'new_leads'],
                'website_sections' => ['header', 'hero', 'about', 'gallery', 'booking', 'faq', 'contact', 'footer'],
                'booking_resource_label' => 'Turf',
                'booking' => [
                    'slot_interval' => 60,
                    'default_hours' => [
                        ['weekday' => 1, 'starts_at' => '06:00', 'ends_at' => '23:00'],
                        ['weekday' => 2, 'starts_at' => '06:00', 'ends_at' => '23:00'],
                        ['weekday' => 3, 'starts_at' => '06:00', 'ends_at' => '23:00'],
                        ['weekday' => 4, 'starts_at' => '06:00', 'ends_at' => '23:00'],
                        ['weekday' => 5, 'starts_at' => '06:00', 'ends_at' => '23:00'],
                        ['weekday' => 6, 'starts_at' => '06:00', 'ends_at' => '23:00'],
                        ['weekday' => 7, 'starts_at' => '06:00', 'ends_at' => '23:00'],
                    ],
                ],
            ],
        ],
        'coaching' => [
            'name' => 'Coaching Centre',
            'description' => 'Coaching classes, tuition and academies.',
            'icon' => 'school',
            'version' => '1.0',
            'website_templates' => ['corporate', 'modern', 'minimal'],
            'engines' => ['education'],
            'modules' => ['crm', 'leads', 'customers', 'messaging', 'automation', 'website', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['new_enquiries', 'admissions', 'students', 'fees_due', 'demo_classes'],
                'website_sections' => ['header', 'hero', 'about', 'courses', 'team', 'testimonials', 'faq', 'contact', 'footer'],
                'lead_stages' => [
                    ['code' => 'new', 'name' => 'New enquiry', 'color' => '#6366f1', 'outcome' => 'open'],
                    ['code' => 'contacted', 'name' => 'Contacted', 'color' => '#0ea5e9', 'outcome' => 'open'],
                    ['code' => 'demo_scheduled', 'name' => 'Demo scheduled', 'color' => '#8b5cf6', 'outcome' => 'open'],
                    ['code' => 'follow_up', 'name' => 'Follow-up', 'color' => '#f59e0b', 'outcome' => 'open'],
                    ['code' => 'converted', 'name' => 'Admitted', 'color' => '#16a34a', 'outcome' => 'won'],
                    ['code' => 'lost', 'name' => 'Not interested', 'color' => '#94a3b8', 'outcome' => 'lost'],
                ],
            ],
        ],
        'cafe' => [
            'name' => 'Cafe & Restaurant',
            'description' => 'Cafes, restaurants and eateries.',
            'icon' => 'cafe',
            'version' => '1.0',
            'website_templates' => ['premium', 'elegant', 'modern'],
            'engines' => ['food', 'commerce'],
            'modules' => ['crm', 'customers', 'messaging', 'offers', 'automation', 'website', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['orders_today', 'revenue_today', 'reservations_today', 'kitchen_queue', 'top_products', 'new_customers'],
                'website_sections' => ['header', 'hero', 'about', 'products', 'reservation', 'gallery', 'offers', 'contact', 'footer'],
                'commerce' => ['online' => ['pickup' => true, 'delivery' => false]],
            ],
        ],
        'clinic' => [
            'name' => 'Clinic',
            'description' => 'Clinics and healthcare practices.',
            'icon' => 'clinic',
            'version' => '1.0',
            'website_templates' => ['minimal', 'corporate', 'modern'],
            'engines' => ['service', 'booking'],
            'modules' => ['crm', 'leads', 'customers', 'messaging', 'automation', 'website', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['appointments_today', 'new_leads', 'pending_followups', 'no_shows'],
                'website_sections' => ['header', 'hero', 'about', 'services', 'team', 'faq', 'contact', 'booking', 'footer'],
                'booking_resource_label' => 'Doctor',
                'service_categories' => ['Consultation', 'Procedures', 'Diagnostics'],
            ],
        ],
        'local_store' => [
            'name' => 'Local Commerce',
            'description' => 'Retail shops, kirana and neighbourhood stores selling in store and online.',
            'icon' => 'store',
            'version' => '1.0',
            'website_templates' => ['modern', 'minimal', 'corporate'],
            'engines' => ['commerce'],
            'modules' => ['crm', 'customers', 'messaging', 'offers', 'automation', 'website', 'inventory', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['orders_today', 'revenue_today', 'low_stock', 'repeat_customers', 'top_products'],
                'website_sections' => ['header', 'hero', 'about', 'products', 'offers', 'contact', 'footer'],
                'commerce' => ['online' => ['pickup' => true, 'delivery' => true]],
            ],
        ],
        'autowave_internal' => [
            'name' => 'AutoWave Internal',
            'description' => 'Preset for the AutoWave Internal tenant (marketing, CRM, demos). Not offered in onboarding.',
            'icon' => 'business',
            'version' => '1.0',
            'public' => false,
            'website_templates' => ['corporate'],
            'engines' => ['service', 'booking'],
            'modules' => ['crm', 'leads', 'customers', 'messaging', 'automation', 'website', 'ai'],
            'configuration' => [
                'dashboard_widgets' => ['new_leads', 'pending_followups'],
                'website_sections' => ['header', 'hero', 'services', 'testimonials', 'faq', 'contact', 'booking', 'footer'],
                'booking_resource_label' => 'Sales Executive',
                'service_categories' => ['Demos', 'Onboarding'],
            ],
        ],
    ],

];

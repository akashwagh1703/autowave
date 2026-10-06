/**
 * Default wording for the public website. Used only where the owner has not written their own text
 * (an empty introduction, subheadline …), so every site reads well from day one. Keyed by section type
 * and, where it helps, by business type code (config/catalog.php).
 */

const EYEBROWS = {
    about: 'Our story',
    services: 'What we offer',
    products: 'Shop',
    courses: 'Learn with us',
    team: 'Our people',
    gallery: 'Inside look',
    video: 'Watch',
    testimonials: 'Kind words',
    offers: 'Special offers',
    faq: 'Good to know',
    downloads: 'Resources',
    contact: 'Visit us',
    booking: 'Instant booking',
    reservation: 'Reservations',
};

const EYEBROW_BY_TYPE = {
    turf: { team: 'Play here', gallery: 'The venue' },
    clinic: { team: 'Expert care', services: 'Care we offer', booking: 'Appointments' },
    coaching: { team: 'Expert teachers', testimonials: 'Results' },
    cafe: { products: 'Freshly made', gallery: 'The place' },
    local_store: { products: 'Everyday essentials' },
};

const INTROS = {
    services: 'Clear prices and no surprises. Pick what you need and book a time that suits you.',
    products: 'Order online and we will get it ready for you.',
    courses: 'Courses and batch timings for every level. Send an enquiry to book a free demo class.',
    team: 'Friendly, experienced people who take care of you from start to finish.',
    gallery: 'A look at our work and our place.',
    video: 'See us in action.',
    testimonials: 'Here is what our customers say about us.',
    offers: 'Limited-time deals. Mention the offer when you book or order.',
    faq: 'Quick answers to the questions we hear most. Still unsure? Just ask us.',
    downloads: 'Brochures, price lists and forms to open or download.',
    contact: 'Call, message or drop in. We usually reply within a few hours.',
    booking: 'Choose a service and a free time. It takes less than a minute.',
    reservation: 'Tell us when you are coming and how many of you. We will confirm shortly.',
};

const INTROS_BY_TYPE = {
    beauty_salon: {
        services: 'From quick trims to bridal makeovers: clear prices, expert hands, and a time that suits you.',
        team: 'Trained stylists and therapists who listen first and take care of every detail.',
        booking: 'Pick a service, your stylist and a free time. You will get a confirmation shortly.',
    },
    clinic: {
        services: 'Consultations and treatments with transparent fees.',
        team: 'Qualified, caring doctors who take the time to explain.',
        booking: 'Choose a consultation and a free time. We will confirm your appointment shortly.',
        faq: 'Answers to common questions about visits, timings and payments.',
    },
    turf: {
        team: 'Well-kept turfs with floodlights for day and night games.',
        booking: 'Pick a date and a free slot. Your game is booked in under a minute.',
        gallery: 'Take a look at the turf before you play.',
    },
    coaching: {
        team: 'Experienced teachers who know the syllabus and care about every student.',
        testimonials: 'Students and parents on what changed for them.',
    },
    cafe: {
        products: 'Freshly made, every day. Order ahead for pickup.',
        gallery: 'Good food, good coffee, good company.',
        reservation: 'Planning to visit? Reserve a table and we will keep it ready.',
    },
    local_store: {
        products: 'Everyday essentials at fair prices. Order online for pickup or home delivery.',
        offers: 'Current deals in store and online.',
    },
};

const HERO_SUBHEADLINES = {
    beauty_salon: 'Hair, skin and beauty care by people who love what they do.',
    clinic: 'Trusted care for you and your family, with appointments that respect your time.',
    turf: 'Floodlit turf, easy online booking and a great game every time.',
    coaching: 'Focused teaching, small batches and real results.',
    cafe: 'Fresh food, great coffee and a place you will want to come back to.',
    local_store: 'Everything you need every day, at fair prices, close to home.',
};

const CTA_BAND = {
    beauty_salon: { title: 'Ready for your next visit?', text: 'Book online in a minute or message us on WhatsApp.' },
    clinic: { title: 'Need an appointment?', text: 'Book online or call us. We will find a time that works for you.' },
    turf: { title: 'Ready to play?', text: 'Grab a slot before your friends do.' },
    coaching: { title: 'Start with a free demo class', text: 'Send an enquiry or call us and we will find the right batch.' },
    cafe: { title: 'Hungry already?', text: 'Order ahead, reserve a table or just drop in.' },
    local_store: { title: 'Need something today?', text: 'Order online or send us your list on WhatsApp.' },
};

/** Headings that replace a section's catalogue default (config/website.php) for a business type. */
const HEADINGS_BY_TYPE = {
    clinic: { services: 'Treatments & consultations', team: 'Meet our doctors', booking: 'Book an appointment' },
    turf: { team: 'Our turfs', booking: 'Book a slot' },
    coaching: { team: 'Meet our faculty', testimonials: 'What students and parents say' },
    cafe: { products: 'Our menu' },
    local_store: { products: 'Shop online' },
};

const DEFAULT_HEADINGS = {
    services: 'Our services',
    products: 'Shop our products',
    team: 'Meet the team',
    testimonials: 'What our customers say',
    booking: 'Book online',
};

export function headingFor(section, typeCode, heading) {
    const replacement = HEADINGS_BY_TYPE[typeCode]?.[section];

    return replacement && (!heading || heading === DEFAULT_HEADINGS[section]) ? replacement : heading;
}

export function eyebrowFor(section, typeCode) {
    return EYEBROW_BY_TYPE[typeCode]?.[section] ?? EYEBROWS[section] ?? null;
}

export function introFor(section, typeCode) {
    return INTROS_BY_TYPE[typeCode]?.[section] ?? INTROS[section] ?? null;
}

export function heroSubheadline(business) {
    const place = business.city ? ` in ${business.city}` : '';

    return business.tagline || business.description || (HERO_SUBHEADLINES[business.type_code] ?? `Welcome to ${business.name}${place}.`);
}

export function ctaBandCopy(typeCode, name) {
    return CTA_BAND[typeCode] ?? { title: `Get in touch with ${name}`, text: 'Call, message or visit us. We are happy to help.' };
}

import LocalCafeIcon from '@mui/icons-material/LocalCafe';
import LocalHospitalIcon from '@mui/icons-material/LocalHospital';
import SchoolIcon from '@mui/icons-material/School';
import SpaIcon from '@mui/icons-material/Spa';
import SportsSoccerIcon from '@mui/icons-material/SportsSoccer';
import StorefrontIcon from '@mui/icons-material/Storefront';

/**
 * Industry pages (/for/{slug}). Slugs, search titles and descriptions are in config/marketing.php;
 * keep both lists in the same order. Only describe what the product does today.
 */
export const INDUSTRIES = {
    salons: {
        name: 'Salons & spas',
        icon: SpaIcon,
        color: '#be185d',
        summary: 'Online appointments with your stylists, a beautiful service menu, products and offers.',
        headline: 'Fill every chair with online booking and WhatsApp reminders',
        intro: 'Give clients a stunning website where they can see your services and prices and book their favourite stylist in seconds, while AutoWave sends reminders and follow-ups for you.',
        mockup: { name: 'Glow Studio', tagline: 'Look your best, every day', items: ['Haircut & styling', 'Classic facial', 'Bridal makeup'], cta: 'Book now' },
        bookingTitle: 'Book with Sana',
        pains: ['Clients call during a haircut and you miss the booking', 'No-shows leave expensive chairs empty', 'Regulars forget to come back for their next visit'],
        features: [
            { title: 'Online booking by stylist', body: 'Clients pick a service, a stylist and a free time. Each stylist only gets the services they do.' },
            { title: 'Service menu with prices', body: 'Hair, skin, nails and makeup grouped neatly with durations and prices on your website.' },
            { title: 'Sell products too', body: 'List serums, shampoos and gift vouchers. Clients order for pickup on their next visit.' },
            { title: 'Reminders that cut no-shows', body: 'Automatic WhatsApp or email reminders before every appointment.' },
            { title: 'Offers that bring clients back', body: 'Show weekday offers on your site and send coupon codes to regulars.' },
            { title: 'Client history', body: 'Every visit, service and chat in one timeline, so you remember what each client likes.' },
        ],
        faqs: [
            { q: 'Can clients choose their stylist?', a: 'Yes. Clients can pick a stylist or choose “anyone available”, and only free slots are shown.' },
            { q: 'Can I block time for breaks or leave?', a: 'Yes. Set working hours for each stylist and block time off, and online booking respects it.' },
            { q: 'Can I sell products on my website?', a: 'Yes. Add products with photos and prices, and clients can order them for pickup.' },
        ],
    },
    clinics: {
        name: 'Clinics',
        icon: LocalHospitalIcon,
        color: '#0284c7',
        summary: 'Appointments by doctor, reminders that reduce no-shows and every enquiry in one place.',
        headline: 'Let patients book appointments online, any time',
        intro: 'A clean, trustworthy clinic website where patients see your doctors, consultations and timings, and book a visit without calling, with reminders sent automatically.',
        mockup: { name: 'CarePoint Clinic', tagline: 'Caring for your family', items: ['General consultation', 'Dental check-up', 'Physiotherapy'], cta: 'Book appointment' },
        bookingTitle: 'Book with Dr. Mehta',
        pains: ['The phone rings all day with appointment requests', 'Patients forget appointments and slots go to waste', 'Enquiries from the website and WhatsApp get lost'],
        features: [
            { title: 'Appointments by doctor', body: 'Patients choose a consultation and a doctor and pick a free time that suits them.' },
            { title: 'Working hours per doctor', body: 'Set each doctor’s days and hours. Only real free slots are offered online.' },
            { title: 'Automatic reminders', body: 'Patients get a reminder before their visit, so fewer appointments are missed.' },
            { title: 'Enquiries become leads', body: 'Questions from your website and WhatsApp are saved and assigned for follow-up.' },
            { title: 'Day calendar for reception', body: 'See the whole day at a glance and add walk-in appointments in seconds.' },
            { title: 'Patient timeline', body: 'Appointments, messages and notes for each patient in one place.' },
        ],
        faqs: [
            { q: 'Can patients book with a specific doctor?', a: 'Yes. Each doctor has their own services and working hours, and patients pick from their free slots.' },
            { q: 'Is patient information kept private?', a: 'Yes. Only your team can see it, and each team member only sees what their role allows.' },
            { q: 'Can reception add walk-in patients?', a: 'Yes. Staff can add appointments from the day calendar, and they block the slot for online booking.' },
        ],
    },
    turfs: {
        name: 'Turfs & sports venues',
        icon: SportsSoccerIcon,
        color: '#16a34a',
        summary: 'Live slot availability, peak and weekend rates, advances and no double bookings.',
        headline: 'Fill every slot with 24×7 online booking for your turf',
        intro: 'Players see live availability and book their slot from your website at midnight or match day, while you set peak, weekend and hourly rates and never double book again.',
        mockup: { name: 'Green Arena', tagline: 'Book your game in seconds', items: ['5-a-side football', 'Box cricket', 'Night slots'], cta: 'Book a slot' },
        bookingTitle: 'Book Turf A',
        pains: ['Booking requests come in at all hours on WhatsApp', 'Two teams turn up for the same slot', 'Working out peak and weekend prices by hand'],
        features: [
            { title: 'Live slot availability', body: 'Players only see open slots, and a booked slot disappears instantly.' },
            { title: 'Peak and weekend rates', body: 'Charge more for evenings and weekends. The right price is worked out for every booking.' },
            { title: 'Multiple turfs and courts', body: 'Manage each ground, court or net separately with its own hours and rates.' },
            { title: 'Advances and dues', body: 'Record advances at booking and see what is still due before the game.' },
            { title: 'Day view of every ground', body: 'See all bookings for the day on one calendar and add walk-ins quickly.' },
            { title: 'Follow up with teams', body: 'Every enquiry and team is saved, so you can invite them back for the next match.' },
        ],
        faqs: [
            { q: 'Can I set different prices for evenings and weekends?', a: 'Yes. Add hourly, peak and weekend rates for each turf, and the booking price is calculated for you.' },
            { q: 'Can I take an advance?', a: 'Yes. Record an advance when the booking is made and track the balance due.' },
            { q: 'Can I manage more than one ground?', a: 'Yes. Add as many turfs, courts or nets as you need, each with its own hours and rates.' },
        ],
    },
    coaching: {
        name: 'Coaching centres',
        icon: SchoolIcon,
        color: '#7c3aed',
        summary: 'Enquiries, demo classes, admissions, batches, attendance and fee reminders.',
        headline: 'Turn more enquiries into admissions',
        intro: 'Show your courses, batches and results on a professional website, capture every enquiry, schedule demo classes and follow up on WhatsApp until the student joins.',
        mockup: { name: 'Bright Minds Academy', tagline: 'Results that speak for themselves', items: ['JEE Foundation', 'Class 10 Boards', 'Spoken English'], cta: 'Book a demo class' },
        bookingTitle: 'Book a demo class',
        pains: ['Parents enquire once and never hear back', 'Demo classes are tracked on paper', 'Chasing fee instalments every month'],
        features: [
            { title: 'Courses and batches online', body: 'List courses with batch timings, start dates and fees on your website.' },
            { title: 'Enquiry pipeline', body: 'Track every enquiry from first call to demo class to admission.' },
            { title: 'Demo classes', body: 'Schedule demo classes for interested students and follow up afterwards.' },
            { title: 'Admissions and fee plans', body: 'Admit students from enquiries with instalment plans that suit parents.' },
            { title: 'Fee reminders', body: 'Automatic reminders before instalments are due, so you stop chasing payments.' },
            { title: 'Attendance', body: 'Mark batch attendance in seconds and keep a record for every student.' },
        ],
        faqs: [
            { q: 'Can parents enquire from the website?', a: 'Yes. Enquiries from your website become leads with the course they are interested in.' },
            { q: 'Can fees be paid in instalments?', a: 'Yes. Create fee plans with instalments, record payments and send reminders before due dates.' },
            { q: 'Can I track demo classes?', a: 'Yes. Schedule demo classes for leads and move them to admission when they join.' },
        ],
    },
    cafes: {
        name: 'Cafes & restaurants',
        icon: LocalCafeIcon,
        color: '#c2410c',
        summary: 'Online menu and orders, table reservations, a kitchen screen and offers.',
        headline: 'Take orders and reservations straight from your website',
        intro: 'A mouth-watering online menu customers can order from for pickup, table reservations without phone calls, and a kitchen screen that keeps orders moving.',
        mockup: { name: 'Brew Lab Cafe', tagline: 'Great coffee, made with love', items: ['Cold coffee', 'Paneer wrap', 'Chocolate brownie'], cta: 'Order now' },
        bookingTitle: 'Reserve a table',
        pains: ['Orders by phone get mixed up', 'Weekend tables are double booked', 'Customers do not know about your offers'],
        features: [
            { title: 'Online menu with ordering', body: 'Veg and non-veg marks, availability and prices. Customers order for pickup.' },
            { title: 'Table reservations', body: 'Guests pick a time and party size, and tables are never double booked.' },
            { title: 'Kitchen screen', body: 'New orders appear in the kitchen instantly, from preparing to ready.' },
            { title: 'Dine-in orders', body: 'Take orders by table from the app and send them straight to the kitchen.' },
            { title: 'Offers and coupons', body: 'Show today’s offers on your site and reward regulars with coupon codes.' },
            { title: 'Know your bestsellers', body: 'See orders, revenue and top items on your dashboard every day.' },
        ],
        faqs: [
            { q: 'Can customers order online?', a: 'Yes. Customers order from your website menu for pickup, and delivery can be switched on in settings.' },
            { q: 'Can I mark items as sold out?', a: 'Yes. Mark items unavailable and they cannot be ordered until you switch them back on.' },
            { q: 'How do table reservations work?', a: 'Add your tables, set reservation hours, and guests book free times from your website.' },
        ],
    },
    stores: {
        name: 'Local stores',
        icon: StorefrontIcon,
        color: '#0d9488',
        summary: 'Your shop online: product catalogue, pickup and delivery orders, stock and offers.',
        headline: 'Put your shop online in an afternoon',
        intro: 'Give your neighbourhood store an online catalogue customers can order from for pickup or delivery, with stock tracked automatically and offers that bring them back.',
        mockup: { name: 'Fresh Basket', tagline: 'Everything you need, delivered', items: ['Fresh fruits', 'Daily essentials', 'Snacks'], cta: 'Shop now' },
        bookingTitle: 'Choose a pickup time',
        pains: ['Customers message their orders and you copy them by hand', 'You run out of best-sellers without noticing', 'Big apps take a cut of every order'],
        features: [
            { title: 'Online catalogue', body: 'Products with photos, prices and categories that customers can browse and order.' },
            { title: 'Pickup and delivery', body: 'Customers choose pickup or delivery, with your own delivery fee.' },
            { title: 'Stock tracking', body: 'Stock goes down with every order and comes back if an order is cancelled.' },
            { title: 'Low-stock alerts', body: 'Get told before a product runs out, so you can reorder in time.' },
            { title: 'Coupons and offers', body: 'Create coupon codes and show offers on your website.' },
            { title: 'Your customers, your data', body: 'Every order builds your customer list for repeat sales, with no commission.' },
        ],
        faqs: [
            { q: 'Do you charge a commission on orders?', a: 'No. You pay a fixed plan price, not a cut of your orders.' },
            { q: 'Can I offer both pickup and delivery?', a: 'Yes. Switch on pickup, delivery or both, and set your delivery fee.' },
            { q: 'Does stock update automatically?', a: 'Yes. Tracked products go down when ordered and come back if the order is cancelled.' },
        ],
    },
};

import { Link } from '@inertiajs/react';
import AutoModeIcon from '@mui/icons-material/AutoMode';
import CardGiftcardIcon from '@mui/icons-material/CardGiftcard';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import EditNoteIcon from '@mui/icons-material/EditNote';
import EventBusyIcon from '@mui/icons-material/EventBusy';
import InsightsIcon from '@mui/icons-material/Insights';
import Inventory2Icon from '@mui/icons-material/Inventory2';
import LockIcon from '@mui/icons-material/Lock';
import NotificationsActiveIcon from '@mui/icons-material/NotificationsActive';
import PhoneMissedIcon from '@mui/icons-material/PhoneMissed';
import RestaurantIcon from '@mui/icons-material/Restaurant';
import SchoolIcon from '@mui/icons-material/School';
import SmartphoneIcon from '@mui/icons-material/Smartphone';
import StorefrontIcon from '@mui/icons-material/Storefront';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import PublicLayout from '@/layouts/PublicLayout';
import { CheckList, Container, CtaBand, CtaButtons, Eyebrow, Faq, SectionHeader, Steps, TrialNotes } from '@/modules/marketing/blocks';
import { INDUSTRIES } from '@/modules/marketing/industries';
import { AssistantMockup, BookingMockup, ChatMockup, DashboardMockup, PipelineMockup, SiteMockup, SitePhoneMockup, Toast } from '@/modules/marketing/mockups';
import { rupees } from '@/utils/billing';

const strengths = [
    { icon: WhatsAppIcon, label: 'Works with WhatsApp' },
    { icon: StorefrontIcon, label: 'Made for Indian businesses' },
    { icon: SmartphoneIcon, label: 'Works on any phone' },
    { icon: LockIcon, label: 'Your data stays private' },
];

const pains = [
    { icon: PhoneMissedIcon, title: 'Enquiries slip away', body: 'Calls, DMs and walk-ins end up in notebooks and chats. Nobody follows up, and the customer books elsewhere.' },
    { icon: EventBusyIcon, title: 'No-shows and double bookings', body: 'Appointments on paper or in memory mean empty slots, clashes and awkward phone calls.' },
    { icon: EditNoteIcon, title: 'Too many apps, no time', body: 'A website here, a booking app there, spreadsheets for the rest. You run the business, not the software.' },
];

const features = [
    {
        id: 'website',
        eyebrow: 'Business website',
        title: 'A good-looking website that brings in customers',
        body: 'Pick a design made for your type of business and your website is ready in minutes. Change a price or a photo in the app and your website updates straight away.',
        points: ['Customers can book, order or send an enquiry from it', 'Looks great on phones, where your customers are', 'Shows your photo and name when you share the link on WhatsApp', 'Free web address: yourbusiness.autowave.co.in'],
        visual: <SiteMockup />,
    },
    {
        id: 'crm',
        eyebrow: 'Enquiries and follow-ups',
        title: 'Never forget to call back an enquiry',
        body: 'Every enquiry from your website, WhatsApp, Instagram or a walk-in is saved in one list. See who to call today, what they asked for and who has already booked.',
        points: ['See who is new, who you called and who booked', 'Reminders so you call back on time', 'Each customer’s visits, orders and chats in one place'],
        visual: <PipelineMockup />,
    },
    {
        id: 'messaging',
        eyebrow: 'Messages',
        title: 'All customer messages on one screen',
        body: 'WhatsApp, Instagram and email messages come to one place. You and your staff can reply from any phone or computer, while reminders and thank-you messages go out by themselves.',
        points: ['WhatsApp, Instagram and email together', 'Reminders and thank-you messages sent automatically', 'AI helps you write replies'],
        visual: <ChatMockup />,
    },
    {
        id: 'bookings',
        eyebrow: 'Bookings and orders',
        title: 'Customers book and order online, day and night',
        body: 'Appointments with your staff, turf and court slots, table reservations, demo classes and product orders, all from your website. Only free times are shown.',
        points: ['No double bookings', 'Pickup and delivery orders, with stock counted for you', 'See who has paid, who paid an advance and who still owes'],
        visual: <BookingMockup />,
    },
];

const assistantPoints = [
    'Replies in seconds, even when you are busy or closed',
    'Customers book, order or reserve a table by tapping buttons',
    'Shows your services and products as photo cards with prices',
    'Answers questions about timings, location, prices and offers',
    'Passes the chat to you when a customer wants a person',
];

const more = [
    { icon: CardGiftcardIcon, title: 'Offers and coupons', body: 'Show offers on your website and give coupon codes that bring customers back.' },
    { icon: InsightsIcon, title: 'Today at a glance', body: 'Sales, bookings, orders and new enquiries on one screen, every morning.' },
    { icon: SchoolIcon, title: 'Courses and fees', body: 'Batches, admissions, attendance and fee reminders for coaching centres.' },
    { icon: RestaurantIcon, title: 'Tables and kitchen', body: 'Table reservations, dine-in orders and a kitchen screen for cafes.' },
    { icon: Inventory2Icon, title: 'Stock tracking', body: 'Know what is in stock and get alerts before you run out.' },
    { icon: AutoModeIcon, title: 'AI writing help', body: 'AI writes replies, offers and website text for you in seconds.' },
];

const steps = [
    { title: 'Sign up free', body: 'Create your account with your email. No card, no setup fee, no technical skills needed.' },
    { title: 'Pick your business type', body: 'Choose salon, clinic, turf, coaching, cafe or store. We set up the right tools and website pages for you.' },
    { title: 'Go live and grow', body: 'Add your services and photos, share your website link on WhatsApp and Instagram, and start taking bookings.' },
];

const faqs = [
    { q: 'Do I need any technical knowledge?', a: 'No. If you can use WhatsApp, you can use AutoWave. Your website is created for you, and you edit it with simple forms.' },
    { q: 'Can I use my own domain name?', a: 'Every business gets a free address like yourbusiness.autowave.co.in. Support for your own domain is coming soon.' },
    {
        q: 'Does it work with WhatsApp?',
        a: 'Yes. Connect your WhatsApp Business number once, and then reply to customers from AutoWave, let the assistant answer them automatically and send reminders.',
    },
    {
        q: 'How do I connect WhatsApp?',
        a: 'AutoWave uses WhatsApp’s official business platform from Meta. You set up your number there and enter its details once in Settings. If you need help, book a free demo and we will show you how.',
    },
    {
        q: 'Will the assistant message my customers on its own?',
        a: 'No. It only replies when a customer messages you first. It passes the chat to you whenever the customer asks for a person or it is not sure what they want.',
    },
    {
        q: 'How is this different from the free WhatsApp Business app?',
        a: 'The free app can send quick replies and show a catalogue. AutoWave also takes the booking or order inside the chat, checks free times and stock, saves every customer, lets your whole team reply from one screen and gives you a website.',
    },
    { q: 'Can customers book and order from my website?', a: 'Yes. Depending on your business, customers can book appointments or slots, reserve tables, order products for pickup or delivery, or send an enquiry.' },
    { q: 'Can my staff use it too?', a: 'Yes. Give each staff member their own login and choose what they can see and do.' },
    { q: 'Is my data safe?', a: 'Your data is stored securely and is never shared with other businesses. You can ask us to delete your account and data at any time.' },
    { q: 'What happens after the free trial?', a: 'Pick a plan that suits you and pay monthly or yearly. Plans never renew automatically, so there are no surprise charges.' },
];

export default function Welcome({ startingPrice, billing }) {
    const trialDays = billing.trial_days;

    return (
        <PublicLayout>
            <Hero trialDays={trialDays} />

            <section aria-label="Why AutoWave" className="border-y border-slate-100 bg-slate-50/70">
                <Container className="grid grid-cols-2 gap-4 py-6 md:grid-cols-4">
                    {strengths.map(({ icon: Icon, label }) => (
                        <p key={label} className="flex items-center justify-center gap-2 text-sm font-medium text-slate-600">
                            <Icon sx={{ fontSize: 20 }} className="text-brand-600" /> {label}
                        </p>
                    ))}
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container>
                    <SectionHeader eyebrow="Sound familiar?" title="Running a local business is hard enough">
                        Most owners lose customers not because of their service, but because of missed messages and messy bookings.
                    </SectionHeader>
                    <div className="mt-12 grid gap-6 md:grid-cols-3">
                        {pains.map(({ icon: Icon, title, body }) => (
                            <div key={title} className="rounded-2xl border border-slate-200 bg-white p-6">
                                <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-rose-50 text-rose-600">
                                    <Icon />
                                </span>
                                <h3 className="font-display mt-4 text-lg font-bold text-ink">{title}</h3>
                                <p className="mt-2 text-slate-600">{body}</p>
                            </div>
                        ))}
                    </div>
                    <p className="font-display mx-auto mt-12 max-w-2xl text-center text-xl font-bold text-ink sm:text-2xl">
                        AutoWave puts your website, bookings, customers and messages in one app, so you never miss a customer again.
                    </p>
                </Container>
            </section>

            <section id="whatsapp-assistant" className="scroll-mt-20 bg-gradient-to-b from-emerald-50/80 to-white py-20 sm:py-24">
                <Container className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <p className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-200">
                            <WhatsAppIcon sx={{ fontSize: 18, color: '#25D366' }} /> New: WhatsApp assistant
                        </p>
                        <h2 className="font-display mt-4 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">Your WhatsApp replies to customers by itself, day and night</h2>
                        <p className="mt-4 text-lg text-slate-600">
                            When a customer messages your business, they get an answer in seconds, with buttons to tap. They can see your prices and
                            photos, book a time or place an order, even at midnight. Everything shows up in AutoWave for you.
                        </p>
                        <CheckList items={assistantPoints} className="mt-6" />
                        <p className="mt-6 text-sm text-slate-500">It only replies to customers who message you first. It never sends messages on its own.</p>
                    </div>
                    <div className="relative mx-auto">
                        <AssistantMockup />
                        <Toast
                            icon={<EventAvailableIcon fontSize="small" />}
                            title="New booking on WhatsApp"
                            body="Haircut · Sun 5:30 pm · Priya"
                            className="absolute top-24 -left-24 hidden w-56 sm:flex"
                        />
                    </div>
                </Container>
            </section>

            <section id="features" className="scroll-mt-20 bg-gradient-to-b from-white via-brand-50/40 to-white py-20 sm:py-24">
                <Container>
                    <SectionHeader eyebrow="Everything in one app" title="All the tools to win and keep customers">
                        Built for how local businesses in India actually work: WhatsApp, walk-ins, UPI and busy days.
                    </SectionHeader>
                    <div className="mt-16 space-y-24">
                        {features.map((feature, index) => (
                            <div key={feature.id} className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                                <div className={index % 2 ? 'lg:order-2' : ''}>
                                    <Eyebrow>{feature.eyebrow}</Eyebrow>
                                    <h3 className="font-display mt-2 text-2xl font-extrabold tracking-tight text-ink sm:text-3xl">{feature.title}</h3>
                                    <p className="mt-4 text-lg text-slate-600">{feature.body}</p>
                                    <CheckList items={feature.points} className="mt-6" />
                                </div>
                                <div className={index % 2 ? 'lg:order-1' : ''}>{feature.visual}</div>
                            </div>
                        ))}
                    </div>

                    <div className="mt-24 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {more.map(({ icon: Icon, title, body }) => (
                            <div key={title} className="flex gap-4 rounded-2xl border border-slate-200 bg-white p-5">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                                    <Icon fontSize="small" />
                                </span>
                                <div>
                                    <h3 className="font-semibold text-ink">{title}</h3>
                                    <p className="mt-1 text-sm text-slate-600">{body}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container>
                    <SectionHeader eyebrow="Made for your industry" title="Set up for the way your business works">
                        Choose your business type and AutoWave switches on the right tools and website sections.
                    </SectionHeader>
                    <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {Object.entries(INDUSTRIES).map(([slug, industry]) => (
                            <Link
                                key={slug}
                                href={`/for/${slug}`}
                                className="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-xl hover:shadow-brand-900/5"
                            >
                                <span className="flex h-11 w-11 items-center justify-center rounded-xl text-white" style={{ backgroundColor: industry.color }}>
                                    <industry.icon />
                                </span>
                                <h3 className="font-display mt-4 text-lg font-bold text-ink">{industry.name}</h3>
                                <p className="mt-1.5 text-sm text-slate-600">{industry.summary}</p>
                                <span className="mt-4 inline-block text-sm font-semibold text-brand-700 group-hover:underline">See how it works →</span>
                            </Link>
                        ))}
                    </div>
                </Container>
            </section>

            <section className="bg-ink py-20 sm:py-24">
                <Container>
                    <SectionHeader eyebrow="How it works" title="Live in three simple steps" tone="light" />
                    <div className="mt-12">
                        <Steps steps={steps} />
                    </div>
                    <div className="mt-12 flex flex-col items-center gap-5">
                        <CtaButtons tone="light" />
                        {startingPrice ? (
                            <p className="text-sm text-slate-300">
                                Plans from <span className="font-semibold text-white">{rupees(startingPrice)}</span> a month after your free trial.{' '}
                                <Link href="/pricing" className="font-semibold text-accent-300 hover:underline">
                                    See pricing
                                </Link>
                            </p>
                        ) : null}
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container>
                    <SectionHeader eyebrow="Questions" title="Frequently asked questions" />
                    <div className="mt-12">
                        <Faq items={faqs} />
                    </div>
                </Container>
            </section>

            <CtaBand trialDays={trialDays} />
        </PublicLayout>
    );
}

function Hero({ trialDays }) {
    return (
        <section className="relative -mt-16 overflow-hidden pt-16">
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10">
                <div className="absolute -top-40 left-1/2 h-[36rem] w-[60rem] -translate-x-1/2 rounded-full bg-gradient-to-br from-brand-200/70 via-brand-100/40 to-accent-300/30 blur-3xl" />
                <div className="absolute inset-0 bg-[radial-gradient(#e0e7ff_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(to_bottom,black,transparent_70%)]" />
            </div>
            <Container className="grid items-center gap-12 pt-12 pb-20 lg:grid-cols-[1.05fr_1fr] lg:pt-20 lg:pb-28">
                <div>
                    <p className="inline-flex items-center gap-2 rounded-full bg-white/80 px-3 py-1 text-sm font-medium text-brand-800 ring-1 ring-brand-200 backdrop-blur">
                        <span className="h-2 w-2 rounded-full bg-accent-500" /> Free {trialDays}-day trial · no payment details needed
                    </p>
                    <h1 className="font-display mt-6 text-4xl leading-[1.08] font-extrabold tracking-tight text-ink sm:text-5xl lg:text-6xl">
                        Your website, bookings and WhatsApp,{' '}
                        <span className="bg-gradient-to-r from-brand-600 to-accent-500 bg-clip-text text-transparent">all in one place.</span>
                    </h1>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed text-slate-600">
                        Get a website for your business, take bookings and orders online, and let WhatsApp reply to customers for you. For salons,
                        clinics, turfs, coaching classes, cafes and shops. Ready in minutes, no tech skills needed.
                    </p>
                    <CtaButtons className="mt-8" />
                    <TrialNotes trialDays={trialDays} className="mt-6" />
                </div>

                <div className="relative mx-auto w-full max-w-xl lg:max-w-none">
                    <DashboardMockup />
                    <div className="absolute -bottom-10 -left-4 hidden sm:block lg:-left-10">
                        <SitePhoneMockup />
                    </div>
                    <Toast
                        icon={<EventAvailableIcon fontSize="small" />}
                        title="New booking"
                        body="Haircut · Sun 5:30 pm"
                        className="absolute -top-5 right-4 hidden w-52 sm:flex lg:-right-6"
                    />
                    <Toast
                        icon={<NotificationsActiveIcon fontSize="small" />}
                        title="Reminder sent on WhatsApp"
                        body="To Priya K. · 2 hours before"
                        className="absolute right-2 -bottom-6 hidden w-60 md:flex"
                    />
                </div>
            </Container>
        </section>
    );
}

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
import { BookingMockup, ChatMockup, DashboardMockup, PipelineMockup, SiteMockup, SitePhoneMockup, Toast } from '@/modules/marketing/mockups';
import { rupees } from '@/utils/billing';

const strengths = [
    { icon: WhatsAppIcon, label: 'WhatsApp-first' },
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
        title: 'A professional website that brings in customers',
        body: 'Pick a design made for your industry and your site is live in minutes. Your services, prices, photos and offers update the moment you change them in the app.',
        points: ['Online booking, ordering and enquiry forms built in', 'Looks great on phones, where your customers are', 'Ready for Google and WhatsApp link previews', 'Your own address: yourbusiness.autowave.co.in'],
        visual: <SiteMockup />,
    },
    {
        id: 'crm',
        eyebrow: 'Leads and CRM',
        title: 'Every enquiry in one place, followed up on time',
        body: 'Leads from your website, WhatsApp, Instagram and walk-ins land in one pipeline. See who to call today, what they asked for and how much business is waiting.',
        points: ['Pipeline stages that match how you sell', 'Follow-up reminders so no lead goes cold', 'Full customer history: visits, orders and chats'],
        visual: <PipelineMockup />,
    },
    {
        id: 'messaging',
        eyebrow: 'WhatsApp and automation',
        title: 'Reply faster. Follow up automatically.',
        body: 'Answer WhatsApp and Instagram messages from a shared inbox. Let automations send reminders, thank-you notes and offers while you focus on customers.',
        points: ['Shared team inbox for WhatsApp, Instagram and email', 'Appointment reminders and follow-ups that run on their own', 'AI suggests replies and fills in lead details for you'],
        visual: <ChatMockup />,
    },
    {
        id: 'bookings',
        eyebrow: 'Bookings and orders',
        title: 'Customers book and order online, 24×7',
        body: 'Appointments with staff, slots for turfs and courts, table reservations, demo classes and product orders, all from your website with live availability.',
        points: ['No double bookings: only free slots are shown', 'Pickup and delivery orders with stock tracking', 'Payments, advances and dues tracked in one place'],
        visual: <BookingMockup />,
    },
];

const more = [
    { icon: CardGiftcardIcon, title: 'Offers and coupons', body: 'Promote offers on your website and give coupon codes that bring customers back.' },
    { icon: InsightsIcon, title: 'Live dashboard', body: 'Revenue, bookings, orders and new leads at a glance, every morning.' },
    { icon: SchoolIcon, title: 'Courses and fees', body: 'Batches, admissions, attendance and fee reminders for coaching centres.' },
    { icon: RestaurantIcon, title: 'Tables and kitchen', body: 'Table reservations, dine-in orders and a kitchen screen for cafes.' },
    { icon: Inventory2Icon, title: 'Stock tracking', body: 'Know what is in stock and get alerts before you run out.' },
    { icon: AutoModeIcon, title: 'AI assistant', body: 'Write replies, offers and website text in seconds.' },
];

const steps = [
    { title: 'Sign up free', body: 'Create your account with your email. No card, no setup fee, no technical skills needed.' },
    { title: 'Pick your business type', body: 'Choose salon, clinic, turf, coaching, cafe or store. We set up the right tools, pages and pipeline for you.' },
    { title: 'Go live and grow', body: 'Add your services and photos, share your website link on WhatsApp and Instagram, and start taking bookings.' },
];

const faqs = [
    { q: 'Do I need any technical knowledge?', a: 'No. If you can use WhatsApp, you can use AutoWave. Your website is created for you, and you edit it with simple forms.' },
    { q: 'Can I use my own domain name?', a: 'Every business gets a free address like yourbusiness.autowave.co.in. Support for your own domain is coming soon.' },
    { q: 'Does it work with WhatsApp?', a: 'Yes. Connect your WhatsApp Business number to reply from the shared inbox and send automatic reminders and follow-ups.' },
    { q: 'Can customers book and order from my website?', a: 'Yes. Depending on your business, customers can book appointments or slots, reserve tables, order products for pickup or delivery, or send an enquiry.' },
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
                        AutoWave brings your website, bookings, customers and messages together, so nothing falls through the cracks.
                    </p>
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
                        Choose your business type and AutoWave switches on the right tools, website sections and sales pipeline.
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
                        AutoWave gives salons, clinics, turfs, coaching centres, cafes and local stores a professional website, online bookings and
                        orders, a lead CRM and automatic WhatsApp follow-ups. Set up in minutes. No tech skills needed.
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

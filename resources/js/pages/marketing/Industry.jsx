import { Link } from '@inertiajs/react';
import CancelIcon from '@mui/icons-material/Cancel';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import PublicLayout from '@/layouts/PublicLayout';
import { Container, CtaBand, CtaButtons, Eyebrow, Faq, SectionHeader, Steps, TrialNotes } from '@/modules/marketing/blocks';
import { INDUSTRIES } from '@/modules/marketing/industries';
import { BookingMockup, ChatMockup, SiteMockup, SitePhoneMockup } from '@/modules/marketing/mockups';
import { rupees } from '@/utils/billing';

export default function Industry({ industry: slug, startingPrice, billing }) {
    const industry = INDUSTRIES[slug];
    const { mockup, color } = industry;
    const others = Object.entries(INDUSTRIES).filter(([key]) => key !== slug);

    return (
        <PublicLayout>
            <section className="relative -mt-16 overflow-hidden pt-16">
                <div aria-hidden="true" className="pointer-events-none absolute inset-0 -z-10">
                    <div className="absolute -top-40 left-1/2 h-[34rem] w-[56rem] -translate-x-1/2 rounded-full opacity-30 blur-3xl" style={{ background: `radial-gradient(circle, ${color}, transparent 70%)` }} />
                </div>
                <Container className="grid items-center gap-12 pt-12 pb-20 lg:grid-cols-2 lg:pt-20">
                    <div>
                        <p className="inline-flex items-center gap-2 rounded-full bg-white/80 px-3 py-1 text-sm font-semibold ring-1 ring-slate-200" style={{ color }}>
                            <industry.icon sx={{ fontSize: 18 }} /> AutoWave for {industry.name.toLowerCase()}
                        </p>
                        <h1 className="font-display mt-6 text-4xl leading-[1.1] font-extrabold tracking-tight text-ink sm:text-5xl">{industry.headline}</h1>
                        <p className="mt-6 max-w-xl text-lg leading-relaxed text-slate-600">{industry.intro}</p>
                        <CtaButtons className="mt-8" />
                        <TrialNotes trialDays={billing.trial_days} className="mt-6" />
                    </div>
                    <div className="relative mx-auto w-full max-w-xl">
                        <SiteMockup {...mockup} color={color} />
                        <div className="absolute -right-2 -bottom-12 hidden sm:block lg:-right-8">
                            <SitePhoneMockup {...mockup} color={color} />
                        </div>
                    </div>
                </Container>
            </section>

            <section className="bg-slate-50 py-20">
                <Container className="grid gap-10 lg:grid-cols-2 lg:items-center">
                    <div>
                        <Eyebrow>The problem</Eyebrow>
                        <h2 className="font-display mt-2 text-3xl font-extrabold tracking-tight text-ink">Sound familiar?</h2>
                        <ul className="mt-6 space-y-3">
                            {industry.pains.map((pain) => (
                                <li key={pain} className="flex items-start gap-3 rounded-xl bg-white p-4 text-slate-700 ring-1 ring-slate-200">
                                    <CancelIcon className="mt-0.5 shrink-0 text-rose-500" fontSize="small" /> {pain}
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="rounded-3xl p-8 text-white" style={{ background: `linear-gradient(135deg, ${color}, #0b1020 160%)` }}>
                        <p className="text-sm font-semibold tracking-wide uppercase opacity-80">With AutoWave</p>
                        <p className="font-display mt-3 text-2xl leading-snug font-extrabold">
                            Customers find you, book or order online and get reminders automatically, while every enquiry is followed up.
                        </p>
                        <ul className="mt-6 space-y-2.5">
                            {industry.features.slice(0, 3).map((feature) => (
                                <li key={feature.title} className="flex items-center gap-2.5">
                                    <CheckCircleIcon fontSize="small" className="text-accent-300" /> {feature.title}
                                </li>
                            ))}
                        </ul>
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container>
                    <SectionHeader eyebrow="Features" title={`Everything ${industry.name.toLowerCase()} need`} />
                    <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {industry.features.map((feature, index) => (
                            <div key={feature.title} className="rounded-2xl border border-slate-200 bg-white p-6">
                                <span className="font-display flex h-9 w-9 items-center justify-center rounded-lg text-sm font-extrabold text-white" style={{ backgroundColor: color }}>
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <h3 className="font-display mt-4 text-lg font-bold text-ink">{feature.title}</h3>
                                <p className="mt-2 text-slate-600">{feature.body}</p>
                            </div>
                        ))}
                    </div>

                    <div className="mt-20 grid items-center gap-10 lg:grid-cols-2">
                        <BookingMockup title={industry.bookingTitle} color={color} />
                        <ChatMockup />
                    </div>
                </Container>
            </section>

            <section className="bg-ink py-20">
                <Container>
                    <SectionHeader eyebrow="Get started" title="Live in three simple steps" tone="light" />
                    <div className="mt-12">
                        <Steps
                            steps={[
                                { title: 'Sign up free', body: `Create your account and choose “${industry.name}”. No card needed.` },
                                { title: 'Add your details', body: 'Your website is ready with the right sections. Add your photos, prices and timings.' },
                                { title: 'Share your link', body: 'Put your website on WhatsApp, Instagram and Google and start taking bookings.' },
                            ]}
                        />
                    </div>
                    {startingPrice ? (
                        <p className="mt-10 text-center text-sm text-slate-300">
                            Plans from <span className="font-semibold text-white">{rupees(startingPrice)}</span> a month after your free trial.{' '}
                            <Link href="/pricing" className="font-semibold text-accent-300 hover:underline">
                                See pricing
                            </Link>
                        </p>
                    ) : null}
                </Container>
            </section>

            <section className="py-20">
                <Container>
                    <SectionHeader eyebrow="Questions" title={`AutoWave for ${industry.name.toLowerCase()}: FAQs`} />
                    <div className="mt-10">
                        <Faq items={industry.faqs} />
                    </div>
                </Container>
            </section>

            <CtaBand trialDays={billing.trial_days} title={`Ready to grow your ${industry.name.toLowerCase().replace(/s( & .*)?$/, '')} business?`} />

            <section className="pb-20">
                <Container>
                    <p className="text-center text-sm font-semibold text-slate-500">AutoWave also works for</p>
                    <div className="mt-4 flex flex-wrap justify-center gap-2">
                        {others.map(([key, other]) => (
                            <Link key={key} href={`/for/${key}`} className="rounded-full px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50">
                                {other.name}
                            </Link>
                        ))}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

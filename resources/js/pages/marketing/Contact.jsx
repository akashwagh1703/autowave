import { Link } from '@inertiajs/react';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import PublicLayout from '@/layouts/PublicLayout';
import { Eyebrow } from '@/modules/marketing/blocks';
import { CompanyDetails } from '@/modules/marketing/LegalPage';

const topics = [
    { title: 'Help with the app', body: 'Tell us your business name and what you were trying to do. A screenshot helps.' },
    { title: 'Plans and payments', body: 'Include your customer ID (Settings → Plan and billing, e.g. AW-123) and the UTR or payment ID.' },
    { title: 'Privacy and your data', body: 'Write from the email address on your account so we can verify it is you.' },
];

export default function Contact({ company, legal, whatsappUrl }) {
    return (
        <PublicLayout>
            <div className="mx-auto max-w-4xl px-4 pt-12 pb-20 sm:px-6">
                <Eyebrow>Contact</Eyebrow>
                <h1 className="font-display mt-2 text-4xl font-extrabold tracking-tight text-ink">We are here to help</h1>
                <p className="mt-3 text-lg text-slate-600">We usually reply within one working day.</p>

                <div className="mt-8 grid gap-4 sm:grid-cols-2">
                    <Link href="/demo" className="flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-brand-300 hover:shadow-lg">
                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                            <EventAvailableIcon />
                        </span>
                        <span>
                            <span className="block font-semibold text-ink">New to AutoWave?</span>
                            <span className="mt-1 block text-sm text-slate-600">Book a free 20-minute demo for your business.</span>
                        </span>
                    </Link>
                    {whatsappUrl ? (
                        <a
                            href={whatsappUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5 transition hover:border-emerald-300 hover:shadow-lg"
                        >
                            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-[#25D366]">
                                <WhatsAppIcon />
                            </span>
                            <span>
                                <span className="block font-semibold text-ink">Chat on WhatsApp</span>
                                <span className="mt-1 block text-sm text-slate-600">Quick questions about plans or the app.</span>
                            </span>
                        </a>
                    ) : null}
                </div>

                <div className="mt-8">
                    <CompanyDetails company={company} grievanceOfficer={legal.grievance_officer} />
                </div>

                <div className="mt-8 grid gap-4 sm:grid-cols-3">
                    {topics.map((topic) => (
                        <div key={topic.title} className="rounded-2xl border border-slate-200 bg-white p-5">
                            <h2 className="font-semibold text-ink">{topic.title}</h2>
                            <p className="mt-1 text-sm text-slate-600">{topic.body}</p>
                        </div>
                    ))}
                </div>

                <p className="mt-8 text-sm text-slate-600">
                    See also our{' '}
                    <Link href="/refunds" className="text-brand-700 hover:underline">
                        Refund and cancellation policy
                    </Link>
                    ,{' '}
                    <Link href="/terms" className="text-brand-700 hover:underline">
                        Terms
                    </Link>{' '}
                    and{' '}
                    <Link href="/privacy" className="text-brand-700 hover:underline">
                        Privacy policy
                    </Link>
                    .
                </p>
            </div>
        </PublicLayout>
    );
}

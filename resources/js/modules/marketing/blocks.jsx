import { Link, usePage } from '@inertiajs/react';
import AddIcon from '@mui/icons-material/Add';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import CheckIcon from '@mui/icons-material/Check';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';

/** Shared building blocks of the marketing pages. */

export function Container({ className = '', children }) {
    return <div className={`mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8 ${className}`}>{children}</div>;
}

export function Eyebrow({ children, tone = 'brand' }) {
    return (
        <p className={`text-sm font-semibold tracking-wide uppercase ${tone === 'light' ? 'text-accent-300' : 'text-brand-600'}`}>{children}</p>
    );
}

export function SectionHeader({ eyebrow, title, children, align = 'center', tone = 'dark' }) {
    return (
        <div className={align === 'center' ? 'mx-auto max-w-2xl text-center' : 'max-w-2xl'}>
            {eyebrow ? <Eyebrow tone={tone === 'light' ? 'light' : 'brand'}>{eyebrow}</Eyebrow> : null}
            <h2 className={`font-display mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl ${tone === 'light' ? 'text-white' : 'text-ink'}`}>{title}</h2>
            {children ? <p className={`mt-4 text-lg ${tone === 'light' ? 'text-slate-300' : 'text-slate-600'}`}>{children}</p> : null}
        </div>
    );
}

const buttonBase = 'inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2';

/** Primary sign-up button and secondary demo button. */
export function CtaButtons({ tone = 'dark', className = '', secondary = 'demo' }) {
    const { appUrl, whatsappUrl } = usePage().props;
    const light = tone === 'light';

    return (
        <div className={`flex flex-col gap-3 sm:flex-row ${className}`}>
            <a
                href={`${appUrl}/register`}
                className={`${buttonBase} ${light ? 'bg-white text-brand-800 hover:bg-brand-50 focus-visible:outline-white' : 'bg-brand-600 text-white shadow-lg shadow-brand-600/25 hover:bg-brand-700 focus-visible:outline-brand-600'}`}
            >
                Start free trial <ArrowForwardIcon sx={{ fontSize: 18 }} />
            </a>
            {secondary === 'whatsapp' && whatsappUrl ? (
                <a
                    href={whatsappUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`${buttonBase} ${light ? 'bg-white/10 text-white ring-1 ring-white/30 hover:bg-white/20' : 'bg-white text-ink ring-1 ring-slate-200 hover:bg-slate-50'}`}
                >
                    <WhatsAppIcon sx={{ fontSize: 18, color: light ? undefined : '#25D366' }} /> Chat on WhatsApp
                </a>
            ) : (
                <Link
                    href="/demo"
                    className={`${buttonBase} ${light ? 'bg-white/10 text-white ring-1 ring-white/30 hover:bg-white/20' : 'bg-white text-ink ring-1 ring-slate-200 hover:bg-slate-50'}`}
                >
                    Book a free demo
                </Link>
            )}
        </div>
    );
}

export function CheckList({ items, className = '', tone = 'dark' }) {
    return (
        <ul className={`space-y-2.5 ${className}`}>
            {items.map((item) => (
                <li key={item} className={`flex items-start gap-2.5 ${tone === 'light' ? 'text-slate-200' : 'text-slate-700'}`}>
                    <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-accent-500/15 text-accent-600">
                        <CheckIcon sx={{ fontSize: 14 }} />
                    </span>
                    <span>{item}</span>
                </li>
            ))}
        </ul>
    );
}

export function TrialNotes({ trialDays = 14, tone = 'dark', className = '' }) {
    return (
        <p className={`flex flex-wrap gap-x-5 gap-y-1 text-sm ${tone === 'light' ? 'text-slate-300' : 'text-slate-500'} ${className}`}>
            {[`${trialDays}-day free trial`, 'No card needed', 'No auto-renewal'].map((note) => (
                <span key={note} className="inline-flex items-center gap-1.5">
                    <CheckIcon sx={{ fontSize: 16 }} className="text-accent-500" /> {note}
                </span>
            ))}
        </p>
    );
}

export function Steps({ steps }) {
    return (
        <ol className="grid gap-6 md:grid-cols-3">
            {steps.map((step, index) => (
                <li key={step.title} className="relative rounded-2xl border border-slate-200 bg-white p-6">
                    <span className="font-display flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-lg font-extrabold text-white">{index + 1}</span>
                    <h3 className="font-display mt-4 text-lg font-bold text-ink">{step.title}</h3>
                    <p className="mt-2 text-slate-600">{step.body}</p>
                </li>
            ))}
        </ol>
    );
}

export function Faq({ items }) {
    return (
        <div className="mx-auto max-w-3xl divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
            {items.map((item) => (
                <details key={item.q} className="group px-6 py-5 [&_summary::-webkit-details-marker]:hidden">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-ink">
                        {item.q}
                        <AddIcon className="shrink-0 text-brand-600 transition group-open:rotate-45" />
                    </summary>
                    <p className="mt-3 text-slate-600">{item.a}</p>
                </details>
            ))}
        </div>
    );
}

/** Closing call to action on a dark brand background. */
export function CtaBand({ title = 'Ready to bring your business online?', body, trialDays }) {
    return (
        <section className="px-4 pb-20 sm:px-6 lg:px-8">
            <div className="relative mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-800 to-brand-950 px-6 py-14 text-center sm:px-12">
                <div aria-hidden="true" className="absolute -top-24 -right-24 h-72 w-72 rounded-full bg-accent-400/20 blur-3xl" />
                <div aria-hidden="true" className="absolute -bottom-24 -left-24 h-72 w-72 rounded-full bg-brand-400/30 blur-3xl" />
                <div className="relative">
                    <h2 className="font-display mx-auto max-w-2xl text-3xl font-extrabold tracking-tight text-white sm:text-4xl">{title}</h2>
                    <p className="mx-auto mt-4 max-w-xl text-lg text-brand-100">
                        {body ?? 'Set up your website, bookings and WhatsApp follow-ups today. Your first customers could find you tomorrow.'}
                    </p>
                    <CtaButtons tone="light" secondary="whatsapp" className="mt-8 justify-center" />
                    <TrialNotes trialDays={trialDays} tone="light" className="mt-6 justify-center" />
                </div>
            </div>
        </section>
    );
}

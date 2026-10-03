import AccessTimeIcon from '@mui/icons-material/AccessTime';
import CallIcon from '@mui/icons-material/Call';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import FacebookIcon from '@mui/icons-material/Facebook';
import FormatQuoteIcon from '@mui/icons-material/FormatQuote';
import InstagramIcon from '@mui/icons-material/Instagram';
import LocalOfferIcon from '@mui/icons-material/LocalOffer';
import PublicIcon from '@mui/icons-material/Public';
import YouTubeIcon from '@mui/icons-material/YouTube';
import { useState } from 'react';
import { formatDuration } from '@/utils/booking';
import { formatMoney } from '@/utils/format';
import { CartButton } from './ShopSection';
import ItemFiles from './ItemFiles';
import { ActionButton, Card, Section, SectionHeading, useSite } from './site';

const NAV = [
    { type: 'services', label: 'Services' },
    { type: 'products', label: 'Shop' },
    { type: 'courses', label: 'Courses' },
    { type: 'team', label: 'Team' },
    { type: 'gallery', label: 'Gallery' },
    { type: 'offers', label: 'Offers' },
    { type: 'booking', label: 'Book' },
    { type: 'reservation', label: 'Reserve' },
    { type: 'contact', label: 'Contact' },
];

export function Header({ config }) {
    const { business, contact, theme, has, shop } = useSite();
    const links = NAV.filter((item) => has(item.type));
    const book = has('booking') ? { href: '#booking', label: 'Book now' } : has('reservation') ? { href: '#reservation', label: 'Reserve a table' } : null;

    return (
        <header className="sticky top-0 z-20 border-b border-slate-100 bg-white/95 backdrop-blur">
            <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                <a href="#top" className="flex min-w-0 items-center gap-3">
                    {business.logo ? <img src={business.logo} alt={business.name} className="h-10 w-auto max-w-[160px] object-contain" /> : null}
                    <span className="truncate text-lg font-bold" style={{ color: theme.color }}>
                        {business.name}
                    </span>
                </a>
                <nav aria-label="Sections" className="hidden items-center gap-5 text-sm font-medium text-slate-600 md:flex">
                    {links.map((item) => (
                        <a key={item.type} href={`#${item.type}`} className="hover:text-slate-900">
                            {item.label}
                        </a>
                    ))}
                </nav>
                <div className="flex shrink-0 items-center gap-2">
                    {config.show_call && contact.phone_href ? (
                        <ActionButton href={contact.phone_href} variant={config.show_book && book ? 'secondary' : 'primary'} aria-label={`Call ${contact.phone}`}>
                            <CallIcon fontSize="small" />
                            <span className="hidden sm:inline">Call us</span>
                        </ActionButton>
                    ) : null}
                    {config.show_book && book ? <ActionButton href={book.href}>{book.label}</ActionButton> : null}
                    {shop ? <CartButton /> : null}
                </div>
            </div>
        </header>
    );
}

export function Hero({ config, data }) {
    const { business, contact, theme, has } = useSite();
    const cta = heroCta(config.cta, contact, has);
    const image = data?.image;
    const style = image
        ? { backgroundImage: `linear-gradient(rgba(15, 23, 42, 0.55), rgba(15, 23, 42, 0.55)), url("${image}")`, backgroundSize: 'cover', backgroundPosition: 'center', color: '#ffffff' }
        : { background: theme.hero.background, color: theme.hero.color };
    const accent = image ? null : theme.hero.accent;

    return (
        <section id="top" style={style}>
            <div className="mx-auto max-w-5xl px-4 py-20 text-center sm:px-6 sm:py-28">
                {business.business_type ? (
                    <p className="text-sm font-semibold tracking-wide uppercase opacity-80" style={accent ? { color: accent } : undefined}>
                        {business.business_type}
                    </p>
                ) : null}
                <h1 className="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">{config.headline || business.name}</h1>
                {config.subheadline || business.tagline ? <p className="mx-auto mt-4 max-w-xl text-lg opacity-90">{config.subheadline || business.tagline}</p> : null}
                {cta ? (
                    <a
                        href={cta.href}
                        target={cta.external ? '_blank' : undefined}
                        rel={cta.external ? 'noopener noreferrer' : undefined}
                        className="mt-8 inline-block px-6 py-3 font-semibold shadow-sm transition hover:opacity-90"
                        style={{ borderRadius: theme.radius, backgroundColor: accent ?? '#ffffff', color: accent ? '#ffffff' : theme.color }}
                    >
                        {cta.label}
                    </a>
                ) : null}
            </div>
        </section>
    );
}

/** The hero button, falling back to the contact section when its target is missing. */
function heroCta(cta, contact, has) {
    if (cta === 'book' && has('booking')) {
        return { href: '#booking', label: 'Book now' };
    }
    if (cta === 'book' && has('reservation')) {
        return { href: '#reservation', label: 'Reserve a table' };
    }
    if (cta === 'whatsapp' && contact.whatsapp_url) {
        return { href: contact.whatsapp_url, label: 'Chat on WhatsApp', external: true };
    }
    if (cta === 'call' && contact.phone_href) {
        return { href: contact.phone_href, label: 'Call us' };
    }

    return has('contact') ? { href: '#contact', label: 'Contact us' } : null;
}

export function About({ config }) {
    const { business } = useSite();

    return (
        <Section id="about" className="max-w-3xl text-center">
            <SectionHeading title={config.heading} />
            <p className="text-lg leading-relaxed whitespace-pre-line text-slate-600">{config.body || business.description}</p>
        </Section>
    );
}

export function Services({ config, data }) {
    const { locale, bookService, has } = useSite();

    return (
        <Section id="services" tone="muted">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className="space-y-10">
                {data.map((group) => (
                    <div key={group.name ?? 'other'}>
                        {group.name && data.length > 1 ? <h3 className="mb-4 text-lg font-semibold text-slate-800">{group.name}</h3> : null}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {group.services.map((service) => (
                                <Card key={service.id} className="flex flex-col">
                                    <div className="flex items-start justify-between gap-4">
                                        <h4 className="font-semibold text-slate-900">{service.name}</h4>
                                        {config.show_prices && service.price !== null ? (
                                            <span className="shrink-0 font-semibold text-slate-900">{formatMoney(service.price, locale.currency)}</span>
                                        ) : null}
                                    </div>
                                    {service.description ? <p className="mt-2 text-sm text-slate-600">{service.description}</p> : null}
                                    <ItemFiles item={service} className="mt-3" />
                                    <div className="mt-auto flex items-center justify-between gap-3 pt-4">
                                        {config.show_duration && service.duration_minutes ? (
                                            <span className="inline-flex items-center gap-1 text-sm text-slate-500">
                                                <AccessTimeIcon sx={{ fontSize: 16 }} />
                                                {formatDuration(service.duration_minutes)}
                                            </span>
                                        ) : (
                                            <span />
                                        )}
                                        {service.bookable && has('booking') ? (
                                            <ActionButton variant="secondary" className="px-3 py-1.5" onClick={() => bookService(service.id)}>
                                                Book
                                            </ActionButton>
                                        ) : null}
                                    </div>
                                </Card>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </Section>
    );
}

export function Team({ config, data }) {
    const { theme } = useSite();

    return (
        <Section id="team">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {data.map((member) => (
                    <Card key={member.id} className="text-center">
                        <div
                            className="mx-auto flex h-16 w-16 items-center justify-center rounded-full text-xl font-bold text-white"
                            style={{ backgroundColor: member.color || theme.color }}
                            aria-hidden="true"
                        >
                            {initials(member.name)}
                        </div>
                        <h3 className="mt-3 font-semibold text-slate-900">{member.name}</h3>
                        {member.description ? <p className="mt-1 text-sm text-slate-600">{member.description}</p> : null}
                        {config.show_services && member.services.length ? <p className="mt-3 text-xs text-slate-500">{member.services.join(' · ')}</p> : null}
                    </Card>
                ))}
            </div>
        </Section>
    );
}

function initials(name) {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}

export function Gallery({ config, data }) {
    const { theme } = useSite();
    const [open, setOpen] = useState(null);

    return (
        <Section id="gallery" tone="muted">
            <SectionHeading title={config.heading} />
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {data.map((image, index) => (
                    <button
                        key={image.url}
                        type="button"
                        onClick={() => setOpen(index)}
                        className="group aspect-square overflow-hidden bg-slate-200"
                        style={{ borderRadius: theme.radius }}
                        aria-label={image.alt ? `Open photo: ${image.alt}` : `Open photo ${index + 1}`}
                    >
                        <img src={image.url} alt={image.alt ?? ''} loading="lazy" className="h-full w-full object-cover transition group-hover:scale-105" />
                    </button>
                ))}
            </div>
            {open !== null ? (
                <div role="dialog" aria-modal="true" aria-label="Photo" className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4" onClick={() => setOpen(null)}>
                    <img src={data[open].url} alt={data[open].alt ?? ''} className="max-h-full max-w-full rounded-md" />
                    <button type="button" className="absolute top-4 right-4 rounded-full bg-white/90 px-3 py-1 text-sm font-semibold" onClick={() => setOpen(null)}>
                        Close
                    </button>
                </div>
            ) : null}
        </Section>
    );
}

export function Testimonials({ config, data }) {
    const { theme } = useSite();

    return (
        <Section id="testimonials">
            <SectionHeading title={config.heading} />
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {data.map((item, index) => (
                    <Card key={index}>
                        <FormatQuoteIcon style={{ color: theme.color }} />
                        <blockquote className="mt-2 whitespace-pre-line text-slate-700">{item.quote}</blockquote>
                        <p className="mt-4 text-sm font-semibold text-slate-900">{item.author}</p>
                        {item.detail ? <p className="text-xs text-slate-500">{item.detail}</p> : null}
                    </Card>
                ))}
            </div>
        </Section>
    );
}

export function Offers({ config, data }) {
    const { theme } = useSite();

    return (
        <Section id="offers" tone="muted">
            <SectionHeading title={config.heading} />
            <div className="grid gap-4 sm:grid-cols-2">
                {data.map((offer, index) => (
                    <Card key={index}>
                        <div className="flex items-start justify-between gap-3">
                            <h3 className="flex items-center gap-2 font-semibold text-slate-900">
                                <LocalOfferIcon fontSize="small" style={{ color: theme.color }} />
                                {offer.title}
                            </h3>
                            {offer.price ? (
                                <span className="shrink-0 rounded-full px-3 py-0.5 text-sm font-semibold text-white" style={{ backgroundColor: theme.color }}>
                                    {offer.price}
                                </span>
                            ) : null}
                        </div>
                        {offer.description ? <p className="mt-2 text-sm whitespace-pre-line text-slate-600">{offer.description}</p> : null}
                        {offer.valid_until ? <p className="mt-3 text-xs text-slate-500">Valid until {offer.valid_until}</p> : null}
                    </Card>
                ))}
            </div>
        </Section>
    );
}

export function Faq({ config, data }) {
    return (
        <Section id="faq" className="max-w-3xl">
            <SectionHeading title={config.heading} />
            <div className="divide-y divide-slate-200 border-y border-slate-200">
                {data.map((item, index) => (
                    <details key={index} className="group py-4">
                        <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-slate-900">
                            {item.question}
                            <ExpandMoreIcon className="shrink-0 transition group-open:rotate-180" />
                        </summary>
                        <p className="mt-3 whitespace-pre-line text-slate-600">{item.answer}</p>
                    </details>
                ))}
            </div>
        </Section>
    );
}

const SOCIAL_ICONS = { instagram: InstagramIcon, facebook: FacebookIcon, youtube: YouTubeIcon };

export function Footer({ config }) {
    const { business, social } = useSite();

    return (
        <footer className="border-t border-slate-100 bg-white">
            <div className="mx-auto flex max-w-5xl flex-col items-center gap-4 px-4 py-8 text-center sm:px-6">
                {config.text ? <p className="max-w-xl text-sm whitespace-pre-line text-slate-600">{config.text}</p> : null}
                {social.length ? (
                    <ul className="flex gap-4">
                        {social.map((link) => {
                            const Icon = SOCIAL_ICONS[link.key] ?? PublicIcon;

                            return (
                                <li key={link.key}>
                                    <a href={link.url} target="_blank" rel="noopener noreferrer" aria-label={link.label} className="text-slate-500 hover:text-slate-900">
                                        <Icon />
                                    </a>
                                </li>
                            );
                        })}
                    </ul>
                ) : null}
                <p className="text-sm text-slate-500">
                    © {new Date().getFullYear()} {business.name}
                </p>
            </div>
        </footer>
    );
}

import { Head } from '@inertiajs/react';
import EmailIcon from '@mui/icons-material/Email';
import PhoneIcon from '@mui/icons-material/Phone';
import PlaceIcon from '@mui/icons-material/Place';
import { fontFamily, heroStyle, radius } from '@/utils/websiteTheme';

function telHref(phone) {
    return `tel:${phone.replace(/[^\d+]/g, '')}`;
}

function Header({ business, contact, color, theme }) {
    return (
        <header className="sticky top-0 z-10 border-b border-slate-100 bg-white/95 backdrop-blur">
            <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <span className="truncate text-xl font-bold" style={{ color }}>
                    {business.name}
                </span>
                {contact.phone ? (
                    <a
                        href={telHref(contact.phone)}
                        className="shrink-0 px-4 py-2 text-sm font-semibold text-white"
                        style={{ backgroundColor: color, borderRadius: radius(theme) }}
                    >
                        Call us
                    </a>
                ) : null}
            </div>
        </header>
    );
}

function Hero({ config, business, color, theme }) {
    const hero = heroStyle(theme, color);
    const cta = config.cta === 'book' ? 'Book now' : 'Contact us';

    return (
        <section style={{ background: hero.background, color: hero.color }}>
            <div className="mx-auto max-w-5xl px-4 py-20 text-center sm:px-6 sm:py-28">
                {business.business_type ? (
                    <p className="text-sm font-semibold tracking-wide uppercase opacity-80" style={hero.accent ? { color: hero.accent } : undefined}>
                        {business.business_type}
                    </p>
                ) : null}
                <h1 className="mt-3 text-4xl font-bold tracking-tight sm:text-5xl">{config.headline || business.name}</h1>
                {config.subheadline ? <p className="mx-auto mt-4 max-w-xl text-lg opacity-90">{config.subheadline}</p> : null}
                <a
                    href="#contact"
                    className="mt-8 inline-block px-6 py-3 font-semibold shadow-sm"
                    style={{
                        borderRadius: radius(theme),
                        backgroundColor: hero.accent ?? '#ffffff',
                        color: hero.accent ? '#ffffff' : color,
                    }}
                >
                    {cta}
                </a>
            </div>
        </section>
    );
}

function About({ config, color }) {
    if (!config.body) {
        return null;
    }

    return (
        <section className="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6">
            <h2 className="text-2xl font-bold text-slate-900">{config.heading || 'About us'}</h2>
            <div className="mx-auto mt-3 h-1 w-12 rounded-full" style={{ backgroundColor: color }} />
            <p className="mt-6 text-lg leading-relaxed whitespace-pre-line text-slate-600">{config.body}</p>
        </section>
    );
}

function Contact({ config, contact, color, theme }) {
    const items = [
        contact.phone && { icon: PhoneIcon, label: contact.phone, href: telHref(contact.phone) },
        contact.email && { icon: EmailIcon, label: contact.email, href: `mailto:${contact.email}` },
        (contact.address || contact.city) && {
            icon: PlaceIcon,
            label: [contact.address, contact.city].filter(Boolean).join(', '),
        },
    ].filter(Boolean);

    if (items.length === 0) {
        return null;
    }

    return (
        <section id="contact" className="bg-slate-50">
            <div className="mx-auto max-w-5xl px-4 py-16 sm:px-6">
                <h2 className="text-center text-2xl font-bold text-slate-900">{config.heading || 'Get in touch'}</h2>
                <ul className="mt-8 grid gap-4 sm:grid-cols-3">
                    {items.map(({ icon: Icon, label, href }) => {
                        const content = (
                            <>
                                <Icon style={{ color }} />
                                <span className="mt-2 block text-sm break-words text-slate-700">{label}</span>
                            </>
                        );

                        return (
                            <li key={label} className="bg-white p-5 text-center shadow-sm" style={{ borderRadius: radius(theme) }}>
                                {href ? (
                                    <a href={href} className="block hover:opacity-80">
                                        {content}
                                    </a>
                                ) : (
                                    content
                                )}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </section>
    );
}

function Footer({ business }) {
    return (
        <footer className="border-t border-slate-100 py-6 text-center text-sm text-slate-500">
            © {new Date().getFullYear()} {business.name}
        </footer>
    );
}

// Section types without renderers yet (services, gallery, booking, ...) are skipped until their data exists.
const RENDERERS = { header: Header, hero: Hero, about: About, contact: Contact, footer: Footer };

export default function Home({ business, template, seo, contact, sections }) {
    const color = business.primary_color || '#4f46e5';

    return (
        <div className="flex min-h-screen flex-col bg-white" style={{ fontFamily: fontFamily(template) }}>
            <Head title={seo.title || business.name}>
                {seo.description ? <meta head-key="description" name="description" content={seo.description} /> : null}
            </Head>

            {sections.map((section) => {
                const Section = RENDERERS[section.type];

                return Section ? (
                    <Section
                        key={section.id}
                        config={section.configuration}
                        business={business}
                        contact={contact}
                        color={color}
                        theme={template}
                    />
                ) : null;
            })}
        </div>
    );
}

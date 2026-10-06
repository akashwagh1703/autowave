import AccessTimeIcon from '@mui/icons-material/AccessTime';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import CallIcon from '@mui/icons-material/Call';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import PlaceIcon from '@mui/icons-material/Place';
import ShoppingBagIcon from '@mui/icons-material/ShoppingBag';
import TableRestaurantIcon from '@mui/icons-material/TableRestaurant';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import { formatDuration } from '@/utils/booking';
import { formatMoney } from '@/utils/format';
import { alpha } from '@/utils/websiteTheme';
import { heroSubheadline } from './copy';
import { ActionButton, Eyebrow, headingStyle, initials, primaryAction, shortPlace, useSite } from './site';

/** The hero button, falling back to the page's main action when its target is missing. */
function heroCta(cta, site) {
    const { contact, has } = site;

    if (cta === 'book' && has('booking')) {
        return { href: '#booking', label: site.business.type_code === 'turf' ? 'Book a slot' : 'Book now', icon: EventAvailableIcon };
    }
    if (cta === 'book' && has('reservation')) {
        return { href: '#reservation', label: 'Reserve a table', icon: TableRestaurantIcon };
    }
    if (cta === 'whatsapp' && contact.whatsapp_url) {
        return { href: contact.whatsapp_url, label: 'Chat on WhatsApp', external: true, icon: WhatsAppIcon };
    }
    if (cta === 'call' && contact.phone_href) {
        return { href: contact.phone_href, label: 'Call us', icon: CallIcon };
    }
    if (cta === 'contact' && has('contact')) {
        return { href: '#contact', label: site.business.type_code === 'coaching' ? 'Book a free demo' : 'Contact us', icon: ArrowForwardIcon };
    }

    const action = primaryAction(site);

    return action ? { href: action.href, label: action.label, icon: ArrowForwardIcon } : null;
}

function secondaryCta(primary, site) {
    const { contact, has, shop } = site;
    const main = primaryAction(site);

    if (main && main.kind !== 'contact' && primary?.href !== main.href) {
        return { href: main.href, label: main.label, icon: main.kind === 'reserve' ? TableRestaurantIcon : main.kind === 'order' ? ShoppingBagIcon : EventAvailableIcon };
    }
    if (contact.whatsapp_url && primary?.href !== contact.whatsapp_url) {
        return { href: contact.whatsapp_url, label: 'WhatsApp us', external: true, icon: WhatsAppIcon };
    }
    if (contact.phone_href && primary?.href !== contact.phone_href) {
        return { href: contact.phone_href, label: 'Call us', icon: CallIcon };
    }
    if (has('services')) {
        return { href: '#services', label: 'See services' };
    }
    if (shop && primary?.href !== '#products') {
        return { href: '#products', label: 'Shop now' };
    }

    return null;
}

/** Short facts shown under the buttons: opening hours, place and what visitors can do online. */
function heroFacts(site) {
    const { contact, has, shop, business } = site;
    const hours = contact.opening_hours?.split('\n')[0]?.trim();
    const place = shortPlace(contact, business.city);
    const online = has('booking')
        ? { icon: EventAvailableIcon, label: 'Instant online booking' }
        : has('reservation')
          ? { icon: TableRestaurantIcon, label: 'Reserve a table online' }
          : shop
            ? { icon: ShoppingBagIcon, label: 'Order online' }
            : null;

    return [hours && { icon: AccessTimeIcon, label: hours }, place && { icon: PlaceIcon, label: place }, online].filter(Boolean).slice(0, 3);
}

/** Up to three real offerings for the hero when there is no photo. */
function showcase(site) {
    const { locale, sectionData, business, booking } = site;
    const services = (sectionData('services') ?? []).flatMap((group) => group.services);
    const resources = booking && !booking.uses_services ? booking.resources : [];
    const products = (sectionData('products') ?? []).flatMap((group) => group.products);
    const courses = sectionData('courses') ?? [];
    const team = sectionData('team') ?? [];

    if (services.length) {
        return {
            title: business.type_code === 'clinic' ? 'Popular treatments' : 'Popular services',
            href: '#services',
            rows: services.slice(0, 3).map((service) => ({
                name: service.name,
                meta: service.duration_minutes ? formatDuration(service.duration_minutes) : null,
                price: service.price !== null ? formatMoney(service.price, locale.currency) : null,
            })),
        };
    }
    if (resources.length) {
        return {
            title: business.type_code === 'turf' ? 'Our turfs' : 'Book online',
            href: '#booking',
            rows: resources.slice(0, 3).map((resource) => ({
                name: resource.name,
                meta: resource.rates?.length ? 'Peak and off-peak rates' : null,
                price: resource.hourly_rate != null ? `${resource.rates?.length ? 'from ' : ''}${formatMoney(Number(resource.hourly_rate), locale.currency)}/hr` : null,
                avatar: resource.color,
            })),
        };
    }
    if (products.length) {
        return {
            title: business.type_code === 'cafe' ? 'From our menu' : 'Bestsellers',
            href: '#products',
            rows: products.slice(0, 3).map((product) => ({ name: product.name, image: product.image, price: formatMoney(Number(product.price), locale.currency) })),
        };
    }
    if (courses.length) {
        return {
            title: 'Our courses',
            href: '#courses',
            rows: courses.slice(0, 3).map((course) => ({ name: course.name, meta: course.duration_label, price: course.fee !== null ? formatMoney(Number(course.fee), locale.currency) : null })),
        };
    }
    if (team.length) {
        return {
            title: business.type_code === 'turf' ? 'Our turfs' : 'Meet the team',
            href: '#team',
            rows: team.slice(0, 3).map((member) => ({ name: member.name, meta: member.description, avatar: member.color })),
        };
    }

    return null;
}

function useHero(config, data) {
    const site = useSite();
    const primary = heroCta(config.cta, site);

    return {
        site,
        theme: site.theme,
        headline: config.headline || site.business.name,
        subheadline: config.subheadline || heroSubheadline(site.business),
        eyebrow: [site.business.business_type, site.business.city].filter(Boolean).join(' · '),
        primary,
        secondary: secondaryCta(primary, site),
        facts: heroFacts(site),
        image: data?.image ?? null,
        showcase: showcase(site),
    };
}

function Cta({ action, variant, size = 'lg', style }) {
    if (!action) {
        return null;
    }

    const Icon = action.icon;

    return (
        <ActionButton href={action.href} variant={variant} size={size} style={style} target={action.external ? '_blank' : undefined} rel={action.external ? 'noopener noreferrer' : undefined}>
            {Icon ? <Icon fontSize="small" /> : null}
            {action.label}
        </ActionButton>
    );
}

function Facts({ facts, className = '', light = false, color }) {
    if (!facts.length) {
        return null;
    }

    return (
        <ul className={`flex flex-wrap gap-x-6 gap-y-3 text-sm ${light ? 'text-white/80' : 'text-slate-600'} ${className}`}>
            {facts.map(({ icon: Icon, label }) => (
                <li key={label} className="flex items-center gap-2">
                    <Icon sx={{ fontSize: 18 }} style={{ color: light ? undefined : color }} />
                    <span className="whitespace-pre-line">{label}</span>
                </li>
            ))}
        </ul>
    );
}

/** A card listing real services, products, courses or team members. */
function ShowcaseCard({ showcase: items, className = '' }) {
    const { theme } = useSite();

    return (
        <div className={`rounded-3xl border border-white/60 bg-white/90 p-6 shadow-[0_30px_60px_-30px_rgba(15,23,42,0.45)] backdrop-blur ${className}`}>
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold text-slate-900">{items.title}</p>
                <a href={items.href} className="flex items-center gap-1 text-xs font-semibold" style={{ color: theme.color }}>
                    See all <ArrowForwardIcon sx={{ fontSize: 14 }} />
                </a>
            </div>
            <ul className="mt-4 divide-y divide-slate-100">
                {items.rows.map((row) => (
                    <li key={row.name} className="flex items-center gap-3 py-3">
                        {row.image ? (
                            <img src={row.image} alt="" className="h-11 w-11 shrink-0 rounded-xl object-cover" />
                        ) : (
                            <span
                                className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sm font-bold"
                                style={{ backgroundColor: row.avatar ?? alpha(theme.color, 0.12), color: row.avatar ? '#ffffff' : theme.color }}
                                aria-hidden="true"
                            >
                                {initials(row.name)}
                            </span>
                        )}
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-semibold text-slate-900">{row.name}</span>
                            {row.meta ? <span className="block truncate text-xs text-slate-500">{row.meta}</span> : null}
                        </span>
                        {row.price ? <span className="shrink-0 text-sm font-bold text-slate-900">{row.price}</span> : null}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Small floating badge used beside hero images. */
function Badge({ icon: Icon, title, text, className = '' }) {
    const { theme } = useSite();

    return (
        <div className={`flex items-center gap-3 rounded-2xl bg-white px-4 py-3 shadow-[0_20px_40px_-20px_rgba(15,23,42,0.45)] ${className}`}>
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: alpha(theme.color, 0.12), color: theme.color }}>
                <Icon fontSize="small" />
            </span>
            <span>
                <span className="block text-sm font-semibold text-slate-900">{title}</span>
                {text ? <span className="block max-w-[14rem] truncate text-xs text-slate-500">{text}</span> : null}
            </span>
        </div>
    );
}

function badgeFor(hero) {
    const online = hero.facts.find((fact) => fact.icon !== AccessTimeIcon && fact.icon !== PlaceIcon);
    const hours = hero.facts.find((fact) => fact.icon === AccessTimeIcon);

    if (online) {
        return { icon: online.icon, title: online.label, text: hours?.label ?? 'Quick confirmation' };
    }

    return hours ? { icon: AccessTimeIcon, title: 'Opening hours', text: hours.label } : null;
}

function Headline({ children, className = '', style = {} }) {
    const { theme } = useSite();

    return (
        <h1 className={`text-balance ${className}`} style={headingStyle(theme, style)}>
            {children}
        </h1>
    );
}

/* ---------- Modern: split layout, soft brand glow, photo or live showcase on the right ---------- */
function SplitHero(hero) {
    const { theme } = hero;
    const badge = badgeFor(hero);

    return (
        <section
            id="top"
            className="relative overflow-hidden"
            style={{
                background: `radial-gradient(900px 520px at 90% -10%, ${alpha(theme.color, 0.22)}, transparent 65%), radial-gradient(700px 420px at -10% 110%, ${alpha(theme.color, 0.14)}, transparent 60%), #ffffff`,
            }}
        >
            <div className="mx-auto grid max-w-6xl items-center gap-14 px-5 pt-14 pb-20 sm:px-8 lg:grid-cols-[1.05fr_0.95fr] lg:pt-20 lg:pb-28">
                <div>
                    {hero.eyebrow ? <Eyebrow>{hero.eyebrow}</Eyebrow> : null}
                    <Headline className="mt-6 text-4xl leading-[1.05] text-slate-900 sm:text-5xl lg:text-6xl">{hero.headline}</Headline>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed text-slate-600">{hero.subheadline}</p>
                    <div className="mt-9 flex flex-wrap gap-3">
                        <Cta action={hero.primary} variant="primary" />
                        <Cta action={hero.secondary} variant="secondary" />
                    </div>
                    <Facts facts={hero.facts} className="mt-10 border-t border-slate-200/80 pt-6" color={theme.color} />
                </div>

                <div className="relative mx-auto w-full max-w-md lg:max-w-none">
                    <div className="absolute -inset-6 -z-0 rounded-[3rem] opacity-70 blur-2xl" style={{ background: `linear-gradient(135deg, ${alpha(theme.color, 0.35)}, transparent 70%)` }} aria-hidden="true" />
                    {hero.image ? (
                        <div className="relative">
                            <img src={hero.image} alt="" className="aspect-[4/5] w-full rounded-[2rem] object-cover shadow-[0_40px_80px_-40px_rgba(15,23,42,0.6)] sm:aspect-[5/5] lg:aspect-[4/5]" />
                            {badge ? <Badge {...badge} className="site-float absolute -bottom-6 -left-4 sm:-left-8" /> : null}
                        </div>
                    ) : hero.showcase ? (
                        <div className="relative">
                            <ShowcaseCard showcase={hero.showcase} className="relative" />
                            {badge ? <Badge {...badge} className="site-float absolute -top-8 -right-2 sm:-right-6" /> : null}
                        </div>
                    ) : (
                        <Monogram className="relative aspect-square rounded-[2rem]" />
                    )}
                </div>
            </div>
        </section>
    );
}

/** Large brand panel with the business initials, for heroes without a photo or records. */
function Monogram({ className = '', style = {} }) {
    const { theme, business } = useSite();

    return (
        <div
            className={`relative flex items-center justify-center overflow-hidden ${className}`}
            style={{ background: `linear-gradient(135deg, ${theme.color}, ${theme.dark})`, color: theme.onColor, ...style }}
            aria-hidden="true"
        >
            <div className="absolute inset-0 opacity-20" style={{ backgroundImage: 'radial-gradient(currentColor 1px, transparent 1px)', backgroundSize: '22px 22px' }} />
            <div className="absolute h-[70%] w-[70%] rounded-full border border-current opacity-25" />
            <div className="absolute h-[95%] w-[95%] rounded-full border border-current opacity-15" />
            <div className="absolute -top-16 -right-16 h-48 w-48 rounded-full bg-white/15 blur-2xl" />
            <span className="relative text-[7rem] leading-none opacity-95" style={headingStyle(theme)}>
                {initials(business.name)}
            </span>
            <span className="absolute inset-x-0 bottom-6 text-center text-xs font-semibold tracking-[0.3em] uppercase opacity-80">{business.name}</span>
        </div>
    );
}

/** Glass cards with a few real items, for the dark hero when there is no photo. */
function Signature({ showcase: items }) {
    const { theme } = useSite();

    return (
        <div className="mt-16 w-full">
            <p className="text-[11px] font-semibold tracking-[0.3em] text-white/50 uppercase">{items.title}</p>
            <ul className={`mt-5 grid gap-3 ${items.rows.length > 1 ? 'sm:grid-cols-3' : 'mx-auto max-w-xs'}`}>
                {items.rows.map((row) => (
                    <li key={row.name}>
                        <a href={items.href} className="flex h-full items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.06] p-4 text-left backdrop-blur-md transition hover:border-white/25 hover:bg-white/10">
                            {row.image ? (
                                <img src={row.image} alt="" className="h-11 w-11 shrink-0 rounded-xl object-cover" />
                            ) : (
                                <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sm font-bold" style={{ backgroundColor: row.avatar ?? alpha(theme.color, 0.22), color: row.avatar ? '#ffffff' : theme.color }} aria-hidden="true">
                                    {initials(row.name)}
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-semibold text-white">{row.name}</span>
                                {row.price || row.meta ? <span className="block truncate text-xs text-white/60">{row.price ?? row.meta}</span> : null}
                            </span>
                        </a>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/* ---------- Premium: full-height, dark and cinematic ---------- */
function CinematicHero(hero) {
    const { theme, site } = hero;
    const info = [
        site.contact.opening_hours && { label: 'Open', value: site.contact.opening_hours.split('\n')[0] },
        (site.contact.address || site.business.city) && { label: 'Find us', value: site.contact.address ?? site.business.city },
        site.contact.phone && { label: 'Call', value: site.contact.phone, href: site.contact.phone_href },
    ].filter(Boolean);

    return (
        <section id="top" className="relative flex min-h-[88vh] flex-col overflow-hidden text-white" style={{ backgroundColor: theme.ink }}>
            {hero.image ? (
                <>
                    <img src={hero.image} alt="" className="absolute inset-0 h-full w-full object-cover" />
                    <div className="absolute inset-0" style={{ background: 'linear-gradient(180deg, rgba(11,16,32,0.55) 0%, rgba(11,16,32,0.7) 55%, rgba(11,16,32,0.95) 100%)' }} />
                </>
            ) : (
                <>
                    <div
                        className="absolute inset-0"
                        style={{ background: `radial-gradient(900px 520px at 50% -5%, ${alpha(theme.color, 0.45)}, transparent 70%), radial-gradient(600px 400px at 100% 100%, ${alpha(theme.color, 0.18)}, transparent 70%)` }}
                    />
                    <div
                        className="absolute inset-0 opacity-[0.07]"
                        style={{
                            backgroundImage: 'linear-gradient(rgba(255,255,255,0.8) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.8) 1px, transparent 1px)',
                            backgroundSize: '64px 64px',
                            maskImage: 'radial-gradient(ellipse at 50% 40%, black 30%, transparent 75%)',
                        }}
                    />
                </>
            )}

            <div className="relative mx-auto flex w-full max-w-5xl flex-1 flex-col items-center justify-center px-5 py-24 text-center sm:px-8">
                {hero.eyebrow ? <Eyebrow>{hero.eyebrow}</Eyebrow> : null}
                <Headline className={`mt-7 leading-[1.02] ${hero.headline.length <= 22 ? 'text-6xl sm:text-7xl lg:text-8xl' : 'text-5xl sm:text-6xl lg:text-7xl'}`}>{hero.headline}</Headline>
                <p className="mt-7 max-w-2xl text-lg leading-relaxed text-white/75 sm:text-xl">{hero.subheadline}</p>
                <div className="mt-10 flex flex-wrap justify-center gap-3">
                    <Cta action={hero.primary} variant="primary" />
                    <Cta action={hero.secondary} variant="ghost" />
                </div>
                {!hero.image && hero.showcase ? <Signature showcase={hero.showcase} /> : null}
            </div>

            {info.length ? (
                <div className="relative border-t border-white/10 bg-black/20 backdrop-blur-sm">
                    <dl className={`mx-auto grid max-w-6xl divide-white/10 px-5 sm:px-8 ${info.length > 1 ? 'sm:grid-cols-3 sm:divide-x' : ''}`}>
                        {info.map((item) => (
                            <div key={item.label} className="py-5 text-center sm:px-6">
                                <dt className="text-[11px] font-semibold tracking-[0.25em] uppercase" style={{ color: theme.color }}>
                                    {item.label}
                                </dt>
                                <dd className="mt-1 truncate text-sm text-white/85">{item.href ? <a href={item.href} className="hover:text-white">{item.value}</a> : item.value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            ) : null}
        </section>
    );
}

/* ---------- Elegant: soft tint, serif type and an arch-framed picture ---------- */
function ArchHero(hero) {
    const { theme } = hero;
    const badge = badgeFor(hero);

    return (
        <section id="top" className="relative overflow-hidden" style={{ backgroundColor: theme.muted }}>
            <div className="absolute -top-40 -left-40 h-[28rem] w-[28rem] rounded-full border" style={{ borderColor: alpha(theme.color, 0.18) }} aria-hidden="true" />
            <div className="absolute -right-24 bottom-[-10rem] h-[22rem] w-[22rem] rounded-full" style={{ backgroundColor: alpha(theme.color, 0.08) }} aria-hidden="true" />

            <div className="relative mx-auto grid max-w-6xl items-center gap-14 px-5 pt-16 pb-20 sm:px-8 lg:grid-cols-[1.1fr_0.9fr] lg:pt-20 lg:pb-24">
                <div className="text-center lg:text-left">
                    {hero.eyebrow ? (
                        <p className="text-xl italic" style={{ fontFamily: theme.heading, color: theme.color }}>
                            {hero.eyebrow}
                        </p>
                    ) : null}
                    <Headline className="mt-4 text-5xl leading-[1.02] text-slate-900 sm:text-6xl lg:text-7xl">{hero.headline}</Headline>
                    <div className="mt-6 flex items-center justify-center gap-2 lg:justify-start" aria-hidden="true">
                        <span className="h-px w-12" style={{ backgroundColor: alpha(theme.color, 0.5) }} />
                        <span className="h-2 w-2 rotate-45" style={{ backgroundColor: theme.color }} />
                        <span className="h-px w-12" style={{ backgroundColor: alpha(theme.color, 0.5) }} />
                    </div>
                    <p className="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-slate-600 lg:mx-0">{hero.subheadline}</p>
                    <div className="mt-9 flex flex-wrap justify-center gap-3 lg:justify-start">
                        <Cta action={hero.primary} variant="primary" />
                        <Cta action={hero.secondary} variant="secondary" />
                    </div>
                    <Facts facts={hero.facts} className="mt-10 justify-center lg:justify-start" color={theme.color} />
                </div>

                <div className="relative mx-auto w-full max-w-sm">
                    <div className="absolute inset-0 translate-x-5 translate-y-5 rounded-t-[999px] rounded-b-3xl border" style={{ borderColor: alpha(theme.color, 0.35) }} aria-hidden="true" />
                    {hero.image ? (
                        <img src={hero.image} alt="" className="relative aspect-[3/4] w-full rounded-t-[999px] rounded-b-3xl border-[6px] border-white object-cover shadow-[0_40px_80px_-40px_rgba(15,23,42,0.5)]" />
                    ) : (
                        <Monogram className="relative aspect-[3/4] w-full rounded-t-[999px] rounded-b-3xl border-[6px] border-white shadow-[0_40px_80px_-40px_rgba(15,23,42,0.5)]" />
                    )}
                    {badge ? <Badge {...badge} className="site-float absolute bottom-10 -left-6 sm:-left-14" /> : null}
                </div>
            </div>
        </section>
    );
}

/* ---------- Minimal: editorial, oversized type, sharp edges ---------- */
function EditorialHero(hero) {
    const { theme, site } = hero;
    const columns = [
        site.contact.opening_hours && { label: 'Hours', value: site.contact.opening_hours },
        (site.contact.address || site.business.city) && { label: 'Location', value: [site.contact.address, site.contact.city].filter(Boolean).join(', ') || site.business.city },
        (site.contact.phone || site.contact.email) && { label: 'Contact', value: [site.contact.phone, site.contact.email].filter(Boolean).join('\n') },
    ].filter(Boolean);

    return (
        <section id="top" className="border-b border-slate-200 bg-white">
            <div className="mx-auto max-w-6xl px-5 pt-14 pb-14 sm:px-8 lg:pt-24 lg:pb-20">
                <div className="flex items-center justify-between gap-4 text-xs font-semibold tracking-[0.2em] text-slate-500 uppercase">
                    <span className="flex items-center gap-2">
                        <span className="h-2 w-2" style={{ backgroundColor: theme.color }} />
                        {site.business.business_type ?? site.business.name}
                    </span>
                    {site.business.city ? <span>{site.business.city}</span> : null}
                </div>
                <Headline className="mt-8 max-w-5xl text-5xl leading-[0.95] text-slate-950 sm:text-7xl lg:text-[6.5rem]">{hero.headline}</Headline>
                <div className="mt-10 flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                    <p className="max-w-xl text-lg leading-relaxed text-slate-600">{hero.subheadline}</p>
                    <div className="flex flex-wrap gap-3">
                        <Cta action={hero.primary} variant="primary" />
                        <Cta action={hero.secondary} variant="secondary" />
                    </div>
                </div>
                {hero.image ? (
                    <img src={hero.image} alt="" className="mt-14 aspect-[16/9] w-full object-cover sm:aspect-[21/9]" />
                ) : columns.length ? (
                    <dl className={`mt-16 grid gap-8 border-t-2 border-slate-900 pt-8 ${columns.length > 1 ? 'sm:grid-cols-3' : ''}`}>
                        {columns.map((column) => (
                            <div key={column.label}>
                                <dt className="text-xs font-semibold tracking-[0.2em] text-slate-500 uppercase">{column.label}</dt>
                                <dd className="mt-2 text-lg font-medium whitespace-pre-line text-slate-900">{column.value}</dd>
                            </div>
                        ))}
                    </dl>
                ) : null}
            </div>
        </section>
    );
}

/* ---------- Corporate: solid brand panel with a quick-action card ---------- */
function PanelHero(hero) {
    const { theme } = hero;
    const onBrand = theme.onColor;
    const light = onBrand === '#ffffff';

    return (
        <section id="top" className="relative overflow-hidden" style={{ backgroundColor: theme.color, color: onBrand }}>
            <div
                className="absolute inset-0 opacity-[0.08]"
                style={{ backgroundImage: `repeating-linear-gradient(135deg, ${onBrand} 0 1px, transparent 1px 22px)` }}
                aria-hidden="true"
            />
            <div className="absolute inset-y-0 right-0 w-1/2" style={{ background: `linear-gradient(90deg, transparent, ${theme.dark})`, opacity: 0.55 }} aria-hidden="true" />

            <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-5 pt-14 pb-20 sm:px-8 lg:grid-cols-2 lg:pt-20 lg:pb-28">
                <div>
                    {hero.eyebrow ? (
                        <span className="flex items-center gap-2 text-xs font-bold tracking-[0.15em] uppercase" style={{ opacity: 0.85 }}>
                            <span className="h-0.5 w-6 rounded-full" style={{ backgroundColor: onBrand }} />
                            {hero.eyebrow}
                        </span>
                    ) : null}
                    <Headline className="mt-6 text-4xl leading-[1.08] sm:text-5xl lg:text-6xl">{hero.headline}</Headline>
                    <p className="mt-6 max-w-xl text-lg leading-relaxed" style={{ opacity: 0.85 }}>
                        {hero.subheadline}
                    </p>
                    <div className="mt-9 flex flex-wrap gap-3">
                        <Cta action={hero.primary} variant="light" />
                        <Cta
                            action={hero.secondary}
                            variant="ghost"
                            style={light ? undefined : { borderColor: alpha('#0f172a', 0.35), color: '#0f172a', backgroundColor: 'rgba(255,255,255,0.25)' }}
                        />
                    </div>
                    <Facts facts={hero.facts} className="mt-10" light={light} color={onBrand} />
                </div>

                <div className="relative mx-auto w-full max-w-md lg:max-w-none">
                    {hero.image ? (
                        <img src={hero.image} alt="" className="aspect-[4/3] w-full rounded-lg object-cover shadow-[0_40px_80px_-30px_rgba(0,0,0,0.6)]" />
                    ) : hero.showcase ? (
                        <ShowcaseCard showcase={hero.showcase} className="rounded-xl text-slate-900" />
                    ) : (
                        <Monogram className="aspect-[4/3] rounded-lg" style={{ background: 'rgba(255,255,255,0.12)', color: onBrand }} />
                    )}
                </div>
            </div>
        </section>
    );
}

const VARIANTS = { split: SplitHero, cinematic: CinematicHero, arch: ArchHero, editorial: EditorialHero, panel: PanelHero };

export default function Hero({ config, data }) {
    const hero = useHero(config, data);
    const Variant = VARIANTS[hero.theme.layout.hero] ?? SplitHero;

    return <Variant {...hero} />;
}

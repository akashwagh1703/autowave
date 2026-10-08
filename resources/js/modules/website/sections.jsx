import AccessTimeIcon from '@mui/icons-material/AccessTime';
import AddIcon from '@mui/icons-material/Add';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import CallIcon from '@mui/icons-material/Call';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import CloseIcon from '@mui/icons-material/Close';
import EmailIcon from '@mui/icons-material/Email';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import FacebookIcon from '@mui/icons-material/Facebook';
import FormatQuoteIcon from '@mui/icons-material/FormatQuote';
import InstagramIcon from '@mui/icons-material/Instagram';
import LocalOfferIcon from '@mui/icons-material/LocalOffer';
import MenuIcon from '@mui/icons-material/Menu';
import PlaceIcon from '@mui/icons-material/Place';
import PublicIcon from '@mui/icons-material/Public';
import ScheduleIcon from '@mui/icons-material/Schedule';
import ShoppingBagIcon from '@mui/icons-material/ShoppingBag';
import TableRestaurantIcon from '@mui/icons-material/TableRestaurant';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import YouTubeIcon from '@mui/icons-material/YouTube';
import { useEffect, useMemo, useState } from 'react';
import { formatDuration } from '@/utils/booking';
import { formatMoney } from '@/utils/format';
import { alpha } from '@/utils/websiteTheme';
import { ctaBandCopy } from './copy';
import ItemFiles from './ItemFiles';
import { CartButton } from './ShopSection';
import { ActionButton, Card, Reveal, Section, SectionHeading, headingStyle, initials, primaryAction, shortPlace, useSite, useTone, whatsappWith } from './site';

const NAV = [
    { type: 'about', label: 'About' },
    { type: 'services', label: 'Services' },
    { type: 'packages', label: 'Packages' },
    { type: 'products', label: 'Shop' },
    { type: 'courses', label: 'Courses' },
    { type: 'team', label: 'Team' },
    { type: 'gallery', label: 'Gallery' },
    { type: 'testimonials', label: 'Reviews' },
    { type: 'offers', label: 'Offers' },
    { type: 'faq', label: 'FAQ' },
    { type: 'contact', label: 'Contact' },
];

const NAV_LABELS = { turf: { team: 'Turfs' }, clinic: { team: 'Doctors', services: 'Treatments' }, coaching: { team: 'Faculty' }, cafe: { products: 'Menu' } };

function useNavLinks() {
    const { has, business } = useSite();

    return NAV.filter((item) => has(item.type)).map((item) => ({ ...item, label: NAV_LABELS[business.type_code]?.[item.type] ?? item.label }));
}

/** The id of the section currently under the header, for highlighting its link. */
function useActiveSection(ids) {
    const [active, setActive] = useState(null);
    const key = ids.join(',');

    useEffect(() => {
        const update = () => {
            const current = ids.filter((id) => {
                const element = document.getElementById(id);

                return element && element.getBoundingClientRect().top <= 140;
            });
            setActive(current.at(-1) ?? null);
        };

        update();
        window.addEventListener('scroll', update, { passive: true });

        return () => window.removeEventListener('scroll', update);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return active;
}

function useScrolled(offset = 12) {
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const update = () => setScrolled(window.scrollY > offset);
        update();
        window.addEventListener('scroll', update, { passive: true });

        return () => window.removeEventListener('scroll', update);
    }, [offset]);

    return scrolled;
}

export function Logo({ light = false, size = 'md' }) {
    const { business, theme } = useSite();
    const box = size === 'lg' ? 'h-12 w-12 text-lg' : 'h-10 w-10 text-base';

    return (
        <span className="flex min-w-0 items-center gap-3">
            {business.logo ? (
                <img src={business.logo} alt="" className={`${size === 'lg' ? 'h-12' : 'h-10'} w-auto max-w-[150px] object-contain`} />
            ) : (
                <span className={`flex shrink-0 items-center justify-center font-bold ${box}`} style={{ backgroundColor: theme.color, color: theme.onColor, borderRadius: theme.radius === '0px' ? '0px' : '12px' }} aria-hidden="true">
                    {initials(business.name)}
                </span>
            )}
            <span className={`truncate text-lg ${light ? 'text-white' : 'text-slate-900'}`} style={headingStyle(theme, { fontWeight: Math.min(theme.headingWeight, 700) })}>
                {business.name}
            </span>
        </span>
    );
}

export function Header({ config }) {
    const site = useSite();
    const { contact, theme, shop } = site;
    const links = useNavLinks();
    const desktopLinks = links.slice(0, 6);
    const active = useActiveSection(links.map((link) => link.type));
    const scrolled = useScrolled();
    const [open, setOpen] = useState(false);
    const dark = theme.layout.dark;
    const action = config.show_book === false ? null : primaryAction(site);

    useEffect(() => {
        document.body.style.overflow = open ? 'hidden' : '';

        return () => {
            document.body.style.overflow = '';
        };
    }, [open]);

    return (
        <header
            className={`sticky top-0 z-40 transition-all duration-300 ${scrolled ? 'shadow-[0_8px_30px_-18px_rgba(15,23,42,0.35)]' : ''} ${dark ? 'text-white' : 'text-slate-700'}`}
            style={{
                backgroundColor: dark ? 'rgba(11,16,32,0.88)' : 'rgba(255,255,255,0.88)',
                backdropFilter: 'blur(14px)',
                borderBottom: `1px solid ${dark ? 'rgba(255,255,255,0.08)' : scrolled ? 'rgba(15,23,42,0.08)' : 'transparent'}`,
            }}
        >
            <div className="mx-auto flex h-[72px] max-w-6xl items-center justify-between gap-4 px-5 sm:px-8">
                <a href="#top" className="min-w-0" onClick={() => setOpen(false)}>
                    <Logo light={dark} />
                </a>

                <nav aria-label="Sections" className="hidden items-center gap-1 lg:flex">
                    {desktopLinks.map((item) => {
                        const current = active === item.type;

                        return (
                            <a
                                key={item.type}
                                href={`#${item.type}`}
                                aria-current={current ? 'true' : undefined}
                                className={`relative rounded-full px-3 py-2 text-sm font-medium transition ${dark ? 'hover:text-white' : 'hover:text-slate-950'} ${current ? (dark ? 'text-white' : 'text-slate-950') : dark ? 'text-white/70' : ''}`}
                            >
                                {item.label}
                                <span className={`absolute inset-x-3 -bottom-0.5 h-0.5 rounded-full transition ${current ? 'opacity-100' : 'opacity-0'}`} style={{ backgroundColor: theme.color }} />
                            </a>
                        );
                    })}
                </nav>

                <div className="flex shrink-0 items-center gap-2">
                    {config.show_call && contact.phone_href ? (
                        <a
                            href={contact.phone_href}
                            aria-label={`Call ${contact.phone}`}
                            className={`hidden items-center gap-2 rounded-full px-3 py-2 text-sm font-semibold transition sm:flex ${dark ? 'text-white/85 hover:text-white' : 'text-slate-700 hover:text-slate-950'}`}
                        >
                            <CallIcon sx={{ fontSize: 18 }} style={{ color: theme.color }} />
                            <span className="hidden xl:inline">{contact.phone}</span>
                        </a>
                    ) : null}
                    {action ? (
                        <span className="hidden sm:block">
                            <ActionButton href={action.href}>{action.label}</ActionButton>
                        </span>
                    ) : null}
                    {shop ? <CartButton /> : null}
                    {links.length ? (
                        <button
                            type="button"
                            onClick={() => setOpen(!open)}
                            aria-expanded={open}
                            aria-controls="site-menu"
                            aria-label={open ? 'Close menu' : 'Open menu'}
                            className={`flex h-10 w-10 items-center justify-center rounded-full transition lg:hidden ${dark ? 'hover:bg-white/10' : 'hover:bg-slate-100'}`}
                        >
                            {open ? <CloseIcon /> : <MenuIcon />}
                        </button>
                    ) : null}
                </div>
            </div>

            {open ? (
                <div id="site-menu" className={`h-[calc(100dvh-72px)] overflow-y-auto border-t px-5 pt-4 pb-10 lg:hidden ${dark ? 'border-white/10' : 'border-slate-100'}`} style={{ backgroundColor: dark ? theme.ink : '#ffffff' }}>
                    <nav aria-label="Menu" className="flex flex-col">
                        {links.map((item) => (
                            <a
                                key={item.type}
                                href={`#${item.type}`}
                                onClick={() => setOpen(false)}
                                className={`border-b py-4 text-lg font-medium ${dark ? 'border-white/10 text-white' : 'border-slate-100 text-slate-900'}`}
                                style={headingStyle(theme, { fontWeight: 600 })}
                            >
                                {item.label}
                            </a>
                        ))}
                    </nav>
                    <div className="mt-8 grid gap-3">
                        {action ? (
                            <ActionButton href={action.href} size="lg" onClick={() => setOpen(false)}>
                                {action.label}
                            </ActionButton>
                        ) : null}
                        {contact.phone_href ? (
                            <ActionButton href={contact.phone_href} size="lg" variant={dark ? 'ghost' : 'secondary'}>
                                <CallIcon fontSize="small" /> Call {contact.phone}
                            </ActionButton>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </header>
    );
}

/** Facts drawn from the business's own records, for the About section. */
function capitalize(text) {
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : text;
}

function aboutStats(site) {
    const { sectionData, business, has } = site;
    const services = (sectionData('services') ?? []).reduce((count, group) => count + group.services.length, 0);
    const products = (sectionData('products') ?? []).reduce((count, group) => count + group.products.length, 0);
    const courses = (sectionData('courses') ?? []).length;
    const team = (sectionData('team') ?? []).length;
    const resources = site.booking && !site.booking.uses_services && !team ? site.booking.resources.length : 0;
    const resourceLabel = site.booking?.resource_label;
    const teamLabel = { turf: team === 1 ? 'Turf' : 'Turfs', clinic: team === 1 ? 'Doctor' : 'Doctors', coaching: team === 1 ? 'Teacher' : 'Teachers' }[business.type_code] ?? (team === 1 ? 'Team member' : 'Team members');

    return [
        services && { value: services, label: services === 1 ? 'Service' : 'Services' },
        products && { value: products, label: business.type_code === 'cafe' ? (products === 1 ? 'Item on the menu' : 'Items on the menu') : products === 1 ? 'Product' : 'Products' },
        courses && { value: courses, label: courses === 1 ? 'Course' : 'Courses' },
        team && { value: team, label: teamLabel },
        resources && resourceLabel && { value: resources, label: capitalize(resources === 1 ? resourceLabel.singular : resourceLabel.plural) },
        has('booking') && { value: '24×7', label: 'Online booking' },
        has('reservation') && { value: '1 min', label: 'To reserve a table online' },
        site.shop && (site.shop.fulfilment?.includes('delivery') ? { value: 'Delivery', label: 'Order online, delivered to you' } : { value: 'Pickup', label: 'Order online, collect in store' }),
    ]
        .filter(Boolean)
        .slice(0, 4);
}

export function About({ config }) {
    const site = useSite();
    const { business, theme, contact, sectionData } = site;
    const body = config.body || business.description;
    const image = (sectionData('gallery') ?? [])[0]?.url;
    const stats = aboutStats(site);
    const action = primaryAction(site);
    const split = Boolean(image) || stats.length >= 2;

    if (!split) {
        return (
            <Section id="about" className="max-w-4xl text-center">
                <SectionHeading title={config.heading} intro={false} align="center" />
                <p className="-mt-2 text-2xl leading-relaxed whitespace-pre-line text-slate-700 sm:text-[1.7rem]" style={headingStyle(theme, { fontWeight: 400, letterSpacing: 'normal' })}>
                    {body}
                </p>
                {stats.length || contact.address || contact.opening_hours ? (
                    <ul className="mt-10 flex flex-wrap justify-center gap-3 text-sm text-slate-700">
                        {stats.map((stat) => (
                            <li key={stat.label} className="rounded-full border border-slate-200 bg-white px-4 py-2">
                                <span className="font-bold" style={{ color: theme.color }}>
                                    {stat.value}
                                </span>{' '}
                                {stat.label}
                            </li>
                        ))}
                        {contact.address ? (
                            <li className="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2">
                                <PlaceIcon sx={{ fontSize: 16 }} style={{ color: theme.color }} />
                                {shortPlace(contact)}
                            </li>
                        ) : null}
                        {contact.opening_hours ? (
                            <li className="flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2">
                                <AccessTimeIcon sx={{ fontSize: 16 }} style={{ color: theme.color }} />
                                {contact.opening_hours.split('\n')[0]}
                            </li>
                        ) : null}
                    </ul>
                ) : null}
                {action ? (
                    <ActionButton href={action.href} className="mt-10" size="lg">
                        {action.label}
                    </ActionButton>
                ) : null}
            </Section>
        );
    }

    return (
        <Section id="about">
            <div className="grid items-center gap-14 lg:grid-cols-2 lg:gap-20">
                <div>
                    <SectionHeading title={config.heading} intro={false} align="left" />
                    <p className="-mt-4 text-lg leading-relaxed whitespace-pre-line text-slate-600">{body}</p>
                    {contact.opening_hours || contact.address ? (
                        <ul className="mt-8 space-y-3 text-slate-700">
                            {contact.address ? (
                                <li className="flex items-start gap-3">
                                    <PlaceIcon sx={{ fontSize: 20 }} style={{ color: theme.color }} className="mt-0.5" />
                                    <span>{[contact.address, contact.city].filter(Boolean).join(', ')}</span>
                                </li>
                            ) : null}
                            {contact.opening_hours ? (
                                <li className="flex items-start gap-3">
                                    <AccessTimeIcon sx={{ fontSize: 20 }} style={{ color: theme.color }} className="mt-0.5" />
                                    <span className="whitespace-pre-line">{contact.opening_hours}</span>
                                </li>
                            ) : null}
                        </ul>
                    ) : null}
                    {action ? (
                        <ActionButton href={action.href} className="mt-9" size="lg">
                            {action.label}
                        </ActionButton>
                    ) : null}
                </div>

                {image ? (
                    <div className="relative">
                        <div className="absolute -inset-3 translate-x-6 translate-y-6" style={{ backgroundColor: alpha(theme.color, 0.12), borderRadius: theme.radius === '0px' ? 0 : '1.75rem' }} aria-hidden="true" />
                        <img src={image} alt="" loading="lazy" className="relative aspect-[4/3] w-full object-cover shadow-xl" style={{ borderRadius: theme.radius === '0px' ? 0 : '1.5rem' }} />
                        {stats.length ? (
                            <div className="absolute -bottom-8 left-6 flex gap-6 bg-white px-6 py-4 shadow-xl" style={{ borderRadius: theme.radius }}>
                                {stats.slice(0, 2).map((stat) => (
                                    <div key={stat.label}>
                                        <p className="text-2xl text-slate-900" style={headingStyle(theme)}>
                                            {stat.value}
                                        </p>
                                        <p className="text-xs font-medium text-slate-500">{stat.label}</p>
                                    </div>
                                ))}
                            </div>
                        ) : null}
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-4">
                        {stats.map((stat, index) => (
                            <Card key={stat.label} className={`p-7 ${stats.length % 2 === 1 && index === stats.length - 1 ? 'col-span-2' : index % 2 === 1 ? 'sm:translate-y-8' : ''}`}>
                                <p className="text-4xl sm:text-5xl" style={headingStyle(theme, { color: theme.color })}>
                                    {stat.value}
                                </p>
                                <p className="mt-2 text-sm font-medium text-slate-600">{stat.label}</p>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </Section>
    );
}

export function Services({ config, data }) {
    const { theme } = useSite();
    const categories = data.filter((group) => group.name).map((group) => group.name);
    const [category, setCategory] = useState('all');
    const services = useMemo(
        () => data.filter((group) => category === 'all' || group.name === category).flatMap((group) => group.services.map((service) => ({ ...service, category: group.name }))),
        [data, category],
    );

    return (
        <Section id="services">
            <SectionHeading title={config.heading} intro={config.intro} />
            {theme.layout.menu ? (
                <ServiceMenu config={config} data={data} />
            ) : (
                <>
                    {categories.length > 1 ? <CategoryPills categories={categories} value={category} onChange={setCategory} /> : null}
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {services.map((service) => (
                            <ServiceCard key={service.id} service={service} config={config} showCategory={categories.length > 1 && category === 'all'} />
                        ))}
                    </div>
                </>
            )}
        </Section>
    );
}

export function Packages({ config, data }) {
    return (
        <Section id="packages" tone="muted">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {data.map((pkg) => (
                    <PackageCard key={pkg.id} pkg={pkg} />
                ))}
            </div>
        </Section>
    );
}

function PackageCard({ pkg }) {
    const { locale, bookService, has, theme } = useSite();

    return (
        <Card hover className="group flex flex-col">
            {pkg.category ? (
                <span className="mb-3 text-xs font-semibold tracking-wide uppercase" style={{ color: theme.color }}>
                    {pkg.category}
                </span>
            ) : null}
            <h3 className="text-lg text-slate-900" style={headingStyle(theme, { fontWeight: Math.min(theme.headingWeight, 700) })}>
                {pkg.name}
            </h3>
            {pkg.description ? <p className="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600">{pkg.description}</p> : null}
            {pkg.includes?.length ? (
                <ul className="mt-4 space-y-1.5 text-sm text-slate-600">
                    {pkg.includes.map((item) => (
                        <li key={item} className="flex gap-2">
                            <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: theme.color }} aria-hidden="true" />
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
            <ItemFiles item={pkg} className="mt-3" />
            <div className="mt-auto flex items-end justify-between gap-3 pt-6">
                <div>
                    {pkg.price !== null ? <p className="text-xl font-bold text-slate-900">{formatMoney(pkg.price, locale.currency)}</p> : null}
                    {pkg.duration_minutes ? (
                        <p className="mt-0.5 flex items-center gap-1 text-xs text-slate-500">
                            <ScheduleIcon sx={{ fontSize: 14 }} />
                            {formatDuration(pkg.duration_minutes)}
                        </p>
                    ) : null}
                </div>
                {pkg.bookable && has('booking') ? (
                    <ActionButton variant="secondary" size="sm" onClick={() => bookService(pkg.id)} aria-label={`Book ${pkg.name}`}>
                        Book
                    </ActionButton>
                ) : null}
            </div>
        </Card>
    );
}

function CategoryPills({ categories, value, onChange }) {
    const { theme } = useSite();
    const align = theme.layout.align === 'center' ? 'justify-center' : '';

    return (
        <div role="tablist" aria-label="Categories" className={`-mt-4 mb-10 flex flex-wrap gap-2 ${align}`}>
            {['all', ...categories].map((name) => {
                const selected = value === name;

                return (
                    <button
                        key={name}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        onClick={() => onChange(name)}
                        className="border px-4 py-2 text-sm font-semibold transition"
                        style={{
                            borderRadius: theme.buttonRadius,
                            backgroundColor: selected ? theme.color : '#ffffff',
                            color: selected ? theme.onColor : '#334155',
                            borderColor: selected ? theme.color : '#e2e8f0',
                        }}
                    >
                        {name === 'all' ? 'All' : name}
                    </button>
                );
            })}
        </div>
    );
}

function ServiceCard({ service, config, showCategory }) {
    const { locale, bookService, has, theme } = useSite();

    return (
        <Card hover className="group flex flex-col">
            {showCategory && service.category ? (
                <span className="mb-3 text-xs font-semibold tracking-wide uppercase" style={{ color: theme.color }}>
                    {service.category}
                </span>
            ) : null}
            <h3 className="text-lg text-slate-900" style={headingStyle(theme, { fontWeight: Math.min(theme.headingWeight, 700) })}>
                {service.name}
            </h3>
            {service.description ? <p className="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600">{service.description}</p> : null}
            <ItemFiles item={service} className="mt-3" />
            <div className="mt-auto flex items-end justify-between gap-3 pt-6">
                <div>
                    {config.show_prices && service.price !== null ? <p className="text-xl font-bold text-slate-900">{formatMoney(service.price, locale.currency)}</p> : null}
                    {config.show_duration && service.duration_minutes ? (
                        <p className="mt-0.5 flex items-center gap-1 text-xs text-slate-500">
                            <ScheduleIcon sx={{ fontSize: 14 }} />
                            {formatDuration(service.duration_minutes)}
                        </p>
                    ) : null}
                </div>
                {service.bookable && has('booking') ? (
                    <ActionButton variant="secondary" size="sm" onClick={() => bookService(service.id)} aria-label={`Book ${service.name}`}>
                        Book
                    </ActionButton>
                ) : null}
            </div>
        </Card>
    );
}

/** Price-list layout (premium and elegant templates): name, dotted leader, price. */
function ServiceMenu({ config, data }) {
    const { locale, bookService, has, theme } = useSite();
    const tone = useTone();
    const onDark = tone === 'dark';

    return (
        <div className={`grid gap-x-16 gap-y-12 ${data.length > 1 ? 'lg:grid-cols-2' : 'mx-auto max-w-3xl'}`}>
            {data.map((group) => (
                <div key={group.name ?? 'other'}>
                    {group.name && data.length > 1 ? (
                        <h3 className="mb-5 border-b pb-3 text-2xl" style={headingStyle(theme, { color: onDark ? '#ffffff' : '#0f172a', borderColor: alpha(theme.color, 0.3) })}>
                            {group.name}
                        </h3>
                    ) : null}
                    <ul className="space-y-6">
                        {group.services.map((service) => (
                            <li key={service.id}>
                                <div className="flex items-baseline gap-3">
                                    <span className="font-semibold text-slate-900">{service.name}</span>
                                    <span className="mb-1 flex-1 border-b border-dotted border-slate-300" aria-hidden="true" />
                                    {config.show_prices && service.price !== null ? (
                                        <span className="font-semibold" style={{ color: theme.color }}>
                                            {formatMoney(service.price, locale.currency)}
                                        </span>
                                    ) : null}
                                </div>
                                <div className="mt-1 flex items-start justify-between gap-4">
                                    <p className="text-sm leading-relaxed text-slate-600">
                                        {service.description}
                                        {config.show_duration && service.duration_minutes ? (
                                            <span className="text-slate-400">
                                                {service.description ? ' · ' : ''}
                                                {formatDuration(service.duration_minutes)}
                                            </span>
                                        ) : null}
                                    </p>
                                    {service.bookable && has('booking') ? (
                                        <button
                                            type="button"
                                            onClick={() => bookService(service.id)}
                                            className="shrink-0 text-sm font-semibold underline-offset-4 hover:underline"
                                            style={{ color: theme.color }}
                                            aria-label={`Book ${service.name}`}
                                        >
                                            Book
                                        </button>
                                    ) : null}
                                </div>
                                <ItemFiles item={service} className="mt-2" />
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}

export function Team({ config, data }) {
    const { theme } = useSite();
    const columns = { 1: 'mx-auto max-w-sm', 2: 'mx-auto max-w-3xl sm:grid-cols-2', 3: 'sm:grid-cols-2 lg:grid-cols-3' }[data.length] ?? 'sm:grid-cols-2 lg:grid-cols-4';

    return (
        <Section id="team">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className={`grid gap-6 ${columns} ${data.length < 4 && data.length > 1 ? 'mx-auto max-w-4xl' : ''}`}>
                {data.map((member) => {
                    const color = member.color || theme.color;

                    return (
                        <Card key={member.id} hover className="text-center">
                            <div className="relative mx-auto h-24 w-24">
                                <div className="absolute inset-0 rounded-full" style={{ background: `conic-gradient(from 180deg, ${color}, ${alpha(color, 0.2)}, ${color})` }} aria-hidden="true" />
                                <div className="absolute inset-[3px] rounded-full bg-white" aria-hidden="true" />
                                <div
                                    className="absolute inset-[7px] flex items-center justify-center rounded-full text-2xl font-bold text-white"
                                    style={{ background: `linear-gradient(135deg, ${color}, ${alpha(color, 0.7)})` }}
                                    aria-hidden="true"
                                >
                                    {initials(member.name)}
                                </div>
                            </div>
                            <h3 className="mt-5 text-lg text-slate-900" style={headingStyle(theme, { fontWeight: Math.min(theme.headingWeight, 700) })}>
                                {member.name}
                            </h3>
                            {member.description ? <p className="mt-1 text-sm text-slate-600">{member.description}</p> : null}
                            {config.show_services && member.services.length ? (
                                <ul className="mt-4 flex flex-wrap justify-center gap-1.5">
                                    {member.services.slice(0, 4).map((service) => (
                                        <li key={service} className="rounded-full px-2.5 py-1 text-xs font-medium" style={{ backgroundColor: alpha(color, 0.1), color }}>
                                            {service}
                                        </li>
                                    ))}
                                    {member.services.length > 4 ? <li className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">+{member.services.length - 4} more</li> : null}
                                </ul>
                            ) : null}
                        </Card>
                    );
                })}
            </div>
        </Section>
    );
}

export function Gallery({ config, data }) {
    const { theme } = useSite();
    const [open, setOpen] = useState(null);
    const rounded = theme.radius === '0px' ? '0px' : '1rem';

    useEffect(() => {
        if (open === null) {
            return undefined;
        }

        const onKey = (event) => {
            if (event.key === 'Escape') setOpen(null);
            if (event.key === 'ArrowRight') setOpen((index) => (index + 1) % data.length);
            if (event.key === 'ArrowLeft') setOpen((index) => (index - 1 + data.length) % data.length);
        };
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [open, data.length]);

    return (
        <Section id="gallery">
            <SectionHeading title={config.heading} />
            <div className="grid auto-rows-[160px] grid-cols-2 gap-3 sm:auto-rows-[200px] md:grid-cols-4 md:gap-4">
                {data.map((image, index) => (
                    <button
                        key={image.url}
                        type="button"
                        onClick={() => setOpen(index)}
                        className={`group relative overflow-hidden bg-slate-200 ${index === 0 && data.length >= 3 ? 'col-span-2 row-span-2' : ''} ${index === 3 && data.length >= 5 ? 'md:col-span-2' : ''}`}
                        style={{ borderRadius: rounded }}
                        aria-label={image.alt ? `Open photo: ${image.alt}` : `Open photo ${index + 1}`}
                    >
                        <img src={image.url} alt={image.alt ?? ''} loading="lazy" className="h-full w-full object-cover transition duration-700 group-hover:scale-110" />
                        <span className="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent opacity-0 transition group-hover:opacity-100" />
                    </button>
                ))}
            </div>
            {open !== null ? (
                <div role="dialog" aria-modal="true" aria-label="Photo" className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4 backdrop-blur-sm" onClick={() => setOpen(null)}>
                    <img src={data[open].url} alt={data[open].alt ?? ''} className="max-h-[85vh] max-w-full rounded-lg shadow-2xl" onClick={(event) => event.stopPropagation()} />
                    <button type="button" aria-label="Close" className="absolute top-4 right-4 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20" onClick={() => setOpen(null)}>
                        <CloseIcon />
                    </button>
                    {data.length > 1 ? (
                        <>
                            <button
                                type="button"
                                aria-label="Previous photo"
                                className="absolute left-3 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 sm:left-6"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    setOpen((open - 1 + data.length) % data.length);
                                }}
                            >
                                <ChevronLeftIcon />
                            </button>
                            <button
                                type="button"
                                aria-label="Next photo"
                                className="absolute right-3 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 sm:right-6"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    setOpen((open + 1) % data.length);
                                }}
                            >
                                <ChevronRightIcon />
                            </button>
                            <p className="absolute bottom-5 text-sm text-white/70">
                                {open + 1} / {data.length}
                            </p>
                        </>
                    ) : null}
                </div>
            ) : null}
        </Section>
    );
}

export function Testimonials({ config, data }) {
    const { theme } = useSite();

    return (
        <Section id="testimonials" tone={theme.layout.dark ? 'dark' : undefined}>
            <SectionHeading title={config.heading} />
            {data.length === 1 ? <FeaturedQuote item={data[0]} /> : <QuoteGrid items={data} />}
        </Section>
    );
}

function FeaturedQuote({ item }) {
    const { theme } = useSite();
    const onDark = useTone() === 'dark';

    return (
        <figure className="mx-auto max-w-3xl text-center">
            <FormatQuoteIcon sx={{ fontSize: 56 }} style={{ color: theme.color }} />
            <blockquote className={`mt-4 text-2xl leading-relaxed whitespace-pre-line sm:text-3xl ${onDark ? 'text-white' : 'text-slate-900'}`} style={headingStyle(theme, { fontWeight: 500 })}>
                {item.quote}
            </blockquote>
            <figcaption className="mt-8">
                <span className={`block font-semibold ${onDark ? 'text-white' : 'text-slate-900'}`}>{item.author}</span>
                {item.detail ? <span className={`text-sm ${onDark ? 'text-white/60' : 'text-slate-500'}`}>{item.detail}</span> : null}
            </figcaption>
        </figure>
    );
}

function QuoteGrid({ items }) {
    const { theme } = useSite();
    const onDark = useTone() === 'dark';

    return (
        <div className={`grid gap-6 ${items.length === 2 ? 'mx-auto max-w-4xl md:grid-cols-2' : 'md:grid-cols-2 lg:grid-cols-3'}`}>
            {items.map((item, index) => (
                <Reveal key={index} delay={index * 80}>
                    <figure
                        className={`flex h-full flex-col border p-7 ${onDark ? 'border-white/10 bg-white/[0.04]' : 'border-slate-200/70 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_24px_-12px_rgba(15,23,42,0.12)]'}`}
                        style={{ borderRadius: theme.radius }}
                    >
                        <FormatQuoteIcon sx={{ fontSize: 36 }} style={{ color: theme.color }} />
                        <blockquote className={`mt-3 flex-1 text-[1.05rem] leading-relaxed whitespace-pre-line ${onDark ? 'text-white/85' : 'text-slate-700'}`}>{item.quote}</blockquote>
                        <figcaption className={`mt-6 flex items-center gap-3 border-t pt-5 ${onDark ? 'border-white/10' : 'border-slate-100'}`}>
                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold" style={{ backgroundColor: alpha(theme.color, onDark ? 0.25 : 0.12), color: onDark ? '#ffffff' : theme.color }} aria-hidden="true">
                                {initials(item.author)}
                            </span>
                            <span>
                                <span className={`block text-sm font-semibold ${onDark ? 'text-white' : 'text-slate-900'}`}>{item.author}</span>
                                {item.detail ? <span className={`block text-xs ${onDark ? 'text-white/55' : 'text-slate-500'}`}>{item.detail}</span> : null}
                            </span>
                        </figcaption>
                    </figure>
                </Reveal>
            ))}
        </div>
    );
}

export function Offers({ config, data }) {
    return (
        <Section id="offers">
            <SectionHeading title={config.heading} />
            <div className={`grid gap-6 ${data.length === 1 ? 'mx-auto max-w-2xl' : 'md:grid-cols-2'}`}>
                {data.map((offer, index) => (
                    <OfferCard key={index} offer={offer} />
                ))}
            </div>
        </Section>
    );
}

function OfferCard({ offer }) {
    const site = useSite();
    const { theme, contact, business } = site;
    const tone = useTone();
    const notch = tone === 'muted' ? theme.muted : '#ffffff';
    const action = primaryAction(site);
    const claim = contact.whatsapp_url
        ? { href: whatsappWith(contact.whatsapp_url, `Hi ${business.name}, I would like to use your offer: ${offer.title}`), label: 'Claim on WhatsApp', external: true }
        : action
          ? { href: action.href, label: action.label }
          : null;

    return (
        <div className="relative flex overflow-hidden border border-slate-200/70 bg-white shadow-[0_8px_24px_-12px_rgba(15,23,42,0.15)]" style={{ borderRadius: theme.radius }}>
            <div className="flex w-28 shrink-0 flex-col items-center justify-center gap-1 p-4 text-center sm:w-36" style={{ background: `linear-gradient(160deg, ${theme.color}, ${theme.dark})`, color: theme.onColor }}>
                <LocalOfferIcon sx={{ fontSize: 22 }} style={{ opacity: 0.85 }} />
                <span className="text-xl leading-tight font-extrabold break-words sm:text-2xl">{offer.price || 'Offer'}</span>
            </div>
            <div className="relative flex-1 border-l-2 border-dashed border-slate-200 p-6">
                <span className="absolute -top-3 -left-3 h-6 w-6 rounded-full" style={{ backgroundColor: notch }} aria-hidden="true" />
                <span className="absolute -bottom-3 -left-3 h-6 w-6 rounded-full" style={{ backgroundColor: notch }} aria-hidden="true" />
                <h3 className="text-lg text-slate-900" style={headingStyle(theme, { fontWeight: Math.min(theme.headingWeight, 700) })}>
                    {offer.title}
                </h3>
                {offer.description ? <p className="mt-2 text-sm leading-relaxed whitespace-pre-line text-slate-600">{offer.description}</p> : null}
                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                    {offer.valid_until ? <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">Valid until {offer.valid_until}</span> : <span />}
                    {claim ? (
                        <a href={claim.href} target={claim.external ? '_blank' : undefined} rel={claim.external ? 'noopener noreferrer' : undefined} className="text-sm font-semibold underline-offset-4 hover:underline" style={{ color: theme.color }}>
                            {claim.label} →
                        </a>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export function Faq({ config, data }) {
    const { theme, contact } = useSite();
    const [open, setOpen] = useState(0);

    return (
        <Section id="faq">
            <div className="grid gap-12 lg:grid-cols-[0.85fr_1.15fr] lg:gap-16">
                <div>
                    <SectionHeading title={config.heading} align="left" />
                    {contact.whatsapp_url || contact.phone_href ? (
                        <div className="-mt-4 border border-slate-200/70 bg-white p-6" style={{ borderRadius: theme.radius }}>
                            <p className="font-semibold text-slate-900">Still have a question?</p>
                            <p className="mt-1 text-sm text-slate-600">We are happy to help. Message or call us.</p>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {contact.whatsapp_url ? (
                                    <ActionButton href={contact.whatsapp_url} variant="whatsapp" size="sm" target="_blank" rel="noopener noreferrer">
                                        <WhatsAppIcon sx={{ fontSize: 18 }} /> WhatsApp
                                    </ActionButton>
                                ) : null}
                                {contact.phone_href ? (
                                    <ActionButton href={contact.phone_href} variant="secondary" size="sm">
                                        <CallIcon sx={{ fontSize: 18 }} /> Call
                                    </ActionButton>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </div>
                <div className="space-y-3">
                    {data.map((item, index) => {
                        const expanded = open === index;

                        return (
                            <div key={index} className="border bg-white transition" style={{ borderRadius: theme.radius, borderColor: expanded ? alpha(theme.color, 0.4) : '#e2e8f0' }}>
                                <h3>
                                    <button
                                        type="button"
                                        onClick={() => setOpen(expanded ? null : index)}
                                        aria-expanded={expanded}
                                        className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left font-semibold text-slate-900"
                                    >
                                        {item.question}
                                        <span
                                            className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition duration-300 ${expanded ? 'rotate-45' : ''}`}
                                            style={{ backgroundColor: expanded ? theme.color : alpha(theme.color, 0.1), color: expanded ? theme.onColor : theme.color }}
                                        >
                                            <AddIcon sx={{ fontSize: 20 }} />
                                        </span>
                                    </button>
                                </h3>
                                <div className={`grid transition-all duration-300 ${expanded ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                                    <div className="overflow-hidden">
                                        <p className="px-6 pb-6 leading-relaxed whitespace-pre-line text-slate-600">{item.answer}</p>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </Section>
    );
}

const ACTION_ICONS = { book: EventAvailableIcon, reserve: TableRestaurantIcon, order: ShoppingBagIcon };

/** Closing call to action before the contact section, added automatically when the site has an action. */
export function CtaBand() {
    const site = useSite();
    const { theme, contact, business } = site;
    const action = primaryAction(site);
    const secondary = contact.whatsapp_url
        ? { href: contact.whatsapp_url, label: 'WhatsApp us', icon: WhatsAppIcon, external: true }
        : contact.phone_href
          ? { href: contact.phone_href, label: 'Call us', icon: CallIcon }
          : null;

    if (!action && !secondary) {
        return null;
    }

    const copy = ctaBandCopy(business.type_code, business.name);
    const ActionIcon = ACTION_ICONS[action?.kind];
    const light = theme.onColor === '#ffffff';
    const SecondaryIcon = secondary?.icon;

    return (
        <section className="bg-white px-5 py-16 sm:px-8 lg:py-20" aria-label={copy.title}>
            <Reveal
                className="relative mx-auto max-w-6xl overflow-hidden px-7 py-12 sm:px-14 sm:py-16"
                style={{ background: `linear-gradient(120deg, ${theme.color} 0%, ${theme.dark} 100%)`, color: theme.onColor, borderRadius: theme.radius === '0px' ? 0 : '1.75rem' }}
            >
                <div className="absolute -top-24 -right-16 h-72 w-72 rounded-full" style={{ backgroundColor: 'rgba(255,255,255,0.1)' }} aria-hidden="true" />
                <div className="absolute -bottom-32 left-1/3 h-72 w-72 rounded-full" style={{ backgroundColor: 'rgba(255,255,255,0.06)' }} aria-hidden="true" />
                <div className="relative flex flex-col gap-8 md:flex-row md:items-center md:justify-between">
                    <div className="max-w-xl">
                        <h2 className="text-3xl leading-tight sm:text-4xl" style={headingStyle(theme)}>
                            {copy.title}
                        </h2>
                        <p className="mt-3 text-lg" style={{ opacity: 0.85 }}>
                            {copy.text}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        {action ? (
                            <ActionButton href={action.href} variant="light" size="lg">
                                {ActionIcon ? <ActionIcon fontSize="small" /> : null}
                                {action.label}
                            </ActionButton>
                        ) : null}
                        {secondary ? (
                            <ActionButton
                                href={secondary.href}
                                variant="ghost"
                                size="lg"
                                target={secondary.external ? '_blank' : undefined}
                                rel={secondary.external ? 'noopener noreferrer' : undefined}
                                style={light ? undefined : { borderColor: 'rgba(15,23,42,0.35)', color: '#0f172a', backgroundColor: 'rgba(255,255,255,0.25)' }}
                            >
                                <SecondaryIcon fontSize="small" />
                                {secondary.label}
                            </ActionButton>
                        ) : null}
                    </div>
                </div>
            </Reveal>
        </section>
    );
}

const SOCIAL_ICONS = { instagram: InstagramIcon, facebook: FacebookIcon, youtube: YouTubeIcon };

export function Footer({ config }) {
    const { business, social, contact, theme } = useSite();
    const links = useNavLinks();
    const place = [contact.address, contact.city].filter(Boolean).join(', ');

    return (
        <footer className="text-white/65" style={{ backgroundColor: theme.ink }}>
            <div className="mx-auto grid max-w-6xl gap-12 px-5 py-16 sm:px-8 md:grid-cols-2 lg:grid-cols-[1.5fr_1fr_1.2fr_1.2fr] lg:py-20">
                <div>
                    <Logo light />
                    <p className="mt-5 max-w-xs text-sm leading-relaxed whitespace-pre-line">{config.text || business.tagline || business.description}</p>
                    {social.length ? (
                        <ul className="mt-6 flex gap-2">
                            {social.map((link) => {
                                const Icon = SOCIAL_ICONS[link.key] ?? PublicIcon;

                                return (
                                    <li key={link.key}>
                                        <a
                                            href={link.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label={link.label}
                                            className="flex h-10 w-10 items-center justify-center rounded-full border border-white/15 text-white/80 transition hover:border-white/40 hover:text-white"
                                        >
                                            <Icon sx={{ fontSize: 20 }} />
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : null}
                </div>

                {links.length ? (
                    <FooterColumn title="Explore">
                        <ul className="space-y-2.5 text-sm">
                            {links.map((item) => (
                                <li key={item.type}>
                                    <a href={`#${item.type}`} className="transition hover:text-white">
                                        {item.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </FooterColumn>
                ) : null}

                {place || contact.opening_hours ? (
                    <FooterColumn title="Visit">
                        <ul className="space-y-3 text-sm">
                            {place ? (
                                <li className="flex gap-2.5">
                                    <PlaceIcon sx={{ fontSize: 18 }} className="mt-0.5 shrink-0" style={{ color: theme.color }} />
                                    {contact.map_url ? (
                                        <a href={contact.map_url} target="_blank" rel="noopener noreferrer" className="transition hover:text-white">
                                            {place}
                                        </a>
                                    ) : (
                                        <span>{place}</span>
                                    )}
                                </li>
                            ) : null}
                            {contact.opening_hours ? (
                                <li className="flex gap-2.5">
                                    <AccessTimeIcon sx={{ fontSize: 18 }} className="mt-0.5 shrink-0" style={{ color: theme.color }} />
                                    <span className="whitespace-pre-line">{contact.opening_hours}</span>
                                </li>
                            ) : null}
                        </ul>
                    </FooterColumn>
                ) : null}

                {contact.phone || contact.email || contact.whatsapp_url ? (
                    <FooterColumn title="Contact">
                        <ul className="space-y-3 text-sm">
                            {contact.phone ? (
                                <li>
                                    <a href={contact.phone_href} className="flex gap-2.5 transition hover:text-white">
                                        <CallIcon sx={{ fontSize: 18 }} className="mt-0.5" style={{ color: theme.color }} />
                                        {contact.phone}
                                    </a>
                                </li>
                            ) : null}
                            {contact.whatsapp_url ? (
                                <li>
                                    <a href={contact.whatsapp_url} target="_blank" rel="noopener noreferrer" className="flex gap-2.5 transition hover:text-white">
                                        <WhatsAppIcon sx={{ fontSize: 18 }} className="mt-0.5" style={{ color: theme.color }} />
                                        WhatsApp
                                    </a>
                                </li>
                            ) : null}
                            {contact.email ? (
                                <li>
                                    <a href={`mailto:${contact.email}`} className="flex gap-2.5 break-all transition hover:text-white">
                                        <EmailIcon sx={{ fontSize: 18 }} className="mt-0.5" style={{ color: theme.color }} />
                                        {contact.email}
                                    </a>
                                </li>
                            ) : null}
                        </ul>
                    </FooterColumn>
                ) : null}
            </div>
            <div className="border-t border-white/10">
                <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-5 py-6 text-sm sm:flex-row sm:px-8">
                    <p>
                        © {new Date().getFullYear()} {business.name}. All rights reserved.
                    </p>
                    <a href="#top" className="flex items-center gap-1.5 transition hover:text-white">
                        Back to top <ArrowUpwardIcon sx={{ fontSize: 16 }} />
                    </a>
                </div>
            </div>
        </footer>
    );
}

function FooterColumn({ title, children }) {
    return (
        <div>
            <h2 className="mb-5 text-xs font-semibold tracking-[0.2em] text-white uppercase">{title}</h2>
            {children}
        </div>
    );
}

/** Thumb-reach actions on phones: call, WhatsApp and the page's main action. */
export function MobileActionBar() {
    const site = useSite();
    const { contact, theme } = site;
    const action = primaryAction(site);
    const items = [
        contact.phone_href && { href: contact.phone_href, label: 'Call', icon: CallIcon },
        contact.whatsapp_url && { href: contact.whatsapp_url, label: 'WhatsApp', icon: WhatsAppIcon, external: true },
        action && action.kind !== 'contact' && { href: action.href, label: action.label, icon: ACTION_ICONS[action.kind] ?? EventAvailableIcon, primary: true },
    ].filter(Boolean);

    if (items.length < 2) {
        return null;
    }

    return (
        <nav aria-label="Quick actions" className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-3 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] backdrop-blur md:hidden">
            <ul className="flex gap-2">
                {items.map(({ href, label, icon: Icon, external, primary }) => (
                    <li key={label} className={primary ? 'flex-[1.6]' : 'flex-1'}>
                        <a
                            href={href}
                            target={external ? '_blank' : undefined}
                            rel={external ? 'noopener noreferrer' : undefined}
                            className="flex h-12 items-center justify-center gap-2 text-sm font-semibold"
                            style={primary ? { backgroundColor: theme.color, color: theme.onColor, borderRadius: theme.buttonRadius } : { color: '#0f172a', backgroundColor: '#f1f5f9', borderRadius: theme.buttonRadius }}
                        >
                            <Icon sx={{ fontSize: 20 }} style={!primary && label === 'WhatsApp' ? { color: '#16a34a' } : undefined} />
                            {label}
                        </a>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

export function shouldShowMobileBar(site) {
    const action = primaryAction(site);

    return [site.contact.phone_href, site.contact.whatsapp_url, action && action.kind !== 'contact'].filter(Boolean).length >= 2;
}

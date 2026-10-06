import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { alpha, fontFamily, heroStyle, radius, readableOn, shade, siteLayout } from '@/utils/websiteTheme';
import { eyebrowFor, headingFor, introFor } from './copy';

/**
 * Shared state of a public tenant website: business details, theme and the cross-section
 * actions (e.g. "Book" on a service jumps to the booking section with that service chosen).
 */
export const SiteContext = createContext(null);

/** Position of the current section on the page; drives alternating backgrounds and numbering. */
export const SectionMeta = createContext({ index: 0, number: null, type: null });

const ToneContext = createContext('base');

export function useSite() {
    return useContext(SiteContext);
}

export function useTone() {
    return useContext(ToneContext);
}

export function siteTheme(template, primaryColor) {
    const color = primaryColor || '#4f46e5';
    const layout = siteLayout(template);
    const rounded = layout.hero === 'split' || layout.hero === 'arch';

    return {
        color,
        onColor: readableOn(color),
        dark: shade(color, 0.35),
        font: fontFamily({ font: 'sans' }),
        heading: layout.heading,
        headingWeight: layout.headingWeight,
        tracking: layout.tracking,
        radius: radius(template),
        buttonRadius: rounded ? '999px' : radius(template),
        hero: heroStyle(template, color),
        layout,
        muted: layout.hero === 'arch' ? `color-mix(in srgb, ${color} 5%, #ffffff)` : layout.hero === 'cinematic' ? '#faf7f2' : '#f8fafc',
        ink: '#0b1020',
    };
}

/** CSS variables used by the site's Tailwind classes (focus rings, accents). */
export function themeVariables(theme) {
    return {
        '--site-brand': theme.color,
        '--site-on-brand': theme.onColor,
        '--site-brand-soft': alpha(theme.color, 0.1),
        '--site-ring': alpha(theme.color, 0.25),
        '--site-radius': theme.radius,
        fontFamily: theme.font,
    };
}

export function headingStyle(theme, extra = {}) {
    return { fontFamily: theme.heading, fontWeight: theme.headingWeight, letterSpacing: theme.tracking, ...extra };
}

export function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** The page's main action: book, reserve, order or contact, whichever the site offers first. */
export function primaryAction(site) {
    if (site.has('booking')) {
        return { href: '#booking', label: site.business.type_code === 'turf' ? 'Book a slot' : 'Book now', kind: 'book' };
    }
    if (site.has('reservation')) {
        return { href: '#reservation', label: 'Reserve a table', kind: 'reserve' };
    }
    if (site.shop) {
        return { href: '#products', label: 'Order online', kind: 'order' };
    }
    if (site.has('contact')) {
        return { href: '#contact', label: site.business.type_code === 'coaching' ? 'Book a free demo' : 'Contact us', kind: 'contact' };
    }

    return null;
}

/** True once the element has scrolled into view (always true without IntersectionObserver). */
export function useInView(options = { rootMargin: '0px 0px -10% 0px' }) {
    const ref = useRef(null);
    const [visible, setVisible] = useState(typeof window === 'undefined' || !('IntersectionObserver' in window));

    useEffect(() => {
        if (visible || !ref.current) {
            return undefined;
        }

        const observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) {
                setVisible(true);
                observer.disconnect();
            }
        }, options);
        observer.observe(ref.current);

        return () => observer.disconnect();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [visible]);

    return [ref, visible];
}

/** Fades its children in when they scroll into view (skipped for reduced motion in app.css). */
export function Reveal({ children, className = '', delay = 0, style = {}, as: Tag = 'div' }) {
    const [ref, visible] = useInView();

    return (
        <Tag ref={ref} className={`site-reveal ${visible ? 'is-visible' : ''} ${className}`} style={delay ? { transitionDelay: `${delay}ms`, ...style } : style}>
            {children}
        </Tag>
    );
}

export function Section({ id, tone, children, className = '' }) {
    const { theme } = useSite();
    const meta = useContext(SectionMeta);
    const resolved = tone === 'dark' || tone === 'brand' ? tone : meta.index % 2 === 1 ? 'muted' : 'base';
    const background = { base: '#ffffff', muted: theme.muted, dark: theme.ink, brand: theme.color }[resolved];
    const color = resolved === 'dark' ? '#ffffff' : resolved === 'brand' ? theme.onColor : undefined;
    const width = className.includes('max-w-') ? '' : 'max-w-6xl';

    return (
        <ToneContext.Provider value={resolved}>
            <section id={id} className="relative scroll-mt-20" style={{ backgroundColor: background, color }}>
                <Reveal className={`mx-auto px-5 py-20 sm:px-8 lg:py-28 ${width} ${className}`}>{children}</Reveal>
            </section>
        </ToneContext.Provider>
    );
}

export function Eyebrow({ children, align = 'center' }) {
    const { theme } = useSite();
    const tone = useTone();
    const onDark = tone === 'dark' || tone === 'brand';
    const style = theme.layout.hero;

    if (!children) {
        return null;
    }

    if (style === 'split') {
        return (
            <span
                className="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold tracking-wide uppercase"
                style={{ backgroundColor: onDark ? 'rgba(255,255,255,0.12)' : alpha(theme.color, 0.1), color: onDark ? '#ffffff' : theme.color }}
            >
                <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: onDark ? '#ffffff' : theme.color }} />
                {children}
            </span>
        );
    }

    if (style === 'cinematic') {
        return (
            <span className={`flex items-center gap-3 text-xs font-semibold tracking-[0.3em] uppercase ${align === 'center' ? 'justify-center' : ''}`} style={{ color: theme.color }}>
                <span className="h-px w-8" style={{ backgroundColor: theme.color }} />
                {children}
                {align === 'center' ? <span className="h-px w-8" style={{ backgroundColor: theme.color }} /> : null}
            </span>
        );
    }

    if (style === 'arch') {
        return (
            <span className="text-lg italic" style={{ fontFamily: theme.heading, color: onDark ? '#ffffff' : theme.color }}>
                {children}
            </span>
        );
    }

    if (style === 'editorial') {
        return <span className={`text-xs font-semibold tracking-[0.2em] uppercase ${onDark ? 'text-white/70' : 'text-slate-500'}`}>{children}</span>;
    }

    return (
        <span className="flex items-center gap-2 text-xs font-bold tracking-[0.15em] uppercase" style={{ color: onDark ? '#ffffff' : theme.color }}>
            <span className="h-0.5 w-6 rounded-full" style={{ backgroundColor: onDark ? '#ffffff' : theme.color }} />
            {children}
        </span>
    );
}

/**
 * Section title with the template's eyebrow, font and alignment. An empty intro falls back to the
 * default wording for the section (copy.js); pass intro={false} for none.
 */
export function SectionHeading({ title, intro, align, eyebrow, children }) {
    const { theme, business } = useSite();
    const meta = useContext(SectionMeta);
    const tone = useTone();
    const onDark = tone === 'dark' || tone === 'brand';
    const side = align ?? theme.layout.align;
    const label = eyebrow ?? eyebrowFor(meta.type, business.type_code);
    const numbered = theme.layout.numbered && meta.number ? `${String(meta.number).padStart(2, '0')} — ${label ?? ''}` : label;
    const text = intro === false ? null : intro || introFor(meta.type, business.type_code);

    return (
        <div className={`mb-12 max-w-2xl lg:mb-14 ${side === 'center' ? 'mx-auto text-center' : ''}`}>
            {numbered ? (
                <div className={`mb-4 flex ${side === 'center' ? 'justify-center' : ''}`}>
                    <Eyebrow align={side}>{numbered}</Eyebrow>
                </div>
            ) : null}
            <h2 className={`text-3xl leading-tight sm:text-4xl lg:text-[2.6rem] ${onDark ? 'text-white' : 'text-slate-900'}`} style={headingStyle(theme)}>
                {headingFor(meta.type, business.type_code, title)}
            </h2>
            {theme.layout.hero === 'arch' ? <Ornament center={side === 'center'} /> : null}
            {text ? <p className={`mt-4 text-lg leading-relaxed whitespace-pre-line ${onDark ? 'text-white/75' : 'text-slate-600'}`}>{text}</p> : null}
            {children}
        </div>
    );
}

function Ornament({ center }) {
    const { theme } = useSite();

    return (
        <div className={`mt-4 flex items-center gap-2 ${center ? 'justify-center' : ''}`} aria-hidden="true">
            <span className="h-px w-10" style={{ backgroundColor: alpha(theme.color, 0.5) }} />
            <span className="h-1.5 w-1.5 rotate-45" style={{ backgroundColor: theme.color }} />
            <span className="h-px w-10" style={{ backgroundColor: alpha(theme.color, 0.5) }} />
        </div>
    );
}

export function Card({ children, className = '', hover = false, style = {} }) {
    const { theme } = useSite();
    const flat = theme.layout.hero === 'editorial';

    return (
        <div
            className={`border bg-white p-6 ${flat ? 'border-slate-200' : 'border-slate-200/70 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_24px_-12px_rgba(15,23,42,0.12)]'} ${
                hover ? 'transition duration-300 hover:-translate-y-1 hover:shadow-[0_20px_40px_-20px_rgba(15,23,42,0.25)]' : ''
            } ${className}`}
            style={{ borderRadius: theme.radius, ...style }}
        >
            {children}
        </div>
    );
}

const SIZES = { sm: 'px-3.5 py-1.5 text-sm', md: 'px-5 py-2.5 text-sm', lg: 'px-7 py-3.5 text-base' };

/**
 * Call to action; renders a link when given href.
 * Variants: primary (brand fill), secondary (brand outline), light (white, for dark or brand backgrounds),
 * ghost (outline on dark backgrounds), whatsapp.
 */
export function ActionButton({ href, onClick, variant = 'primary', size = 'md', children, className = '', style = {}, ...rest }) {
    const { theme } = useSite();
    const styles = {
        primary: { backgroundColor: theme.color, color: theme.onColor, boxShadow: `0 10px 24px -12px ${alpha(theme.color, 0.7)}` },
        secondary: { border: `1.5px solid ${alpha(theme.color, 0.5)}`, color: theme.color, backgroundColor: 'transparent' },
        light: { backgroundColor: '#ffffff', color: theme.ink },
        ghost: { border: '1.5px solid rgba(255,255,255,0.45)', color: '#ffffff', backgroundColor: 'rgba(255,255,255,0.06)' },
        whatsapp: { backgroundColor: '#25D366', color: '#ffffff' },
    };
    const classes = `inline-flex items-center justify-center gap-2 font-semibold whitespace-nowrap transition duration-200 hover:-translate-y-0.5 hover:brightness-105 focus-visible:ring-4 focus-visible:ring-(--site-ring) focus-visible:outline-none active:translate-y-0 disabled:pointer-events-none disabled:opacity-60 ${SIZES[size] ?? SIZES.md} ${className}`;
    const merged = { borderRadius: theme.buttonRadius, ...styles[variant], ...style };

    return href ? (
        <a href={href} onClick={onClick} className={classes} style={merged} {...rest}>
            {children}
        </a>
    ) : (
        <button type="button" onClick={onClick} className={classes} style={merged} {...rest}>
            {children}
        </button>
    );
}

export function Field({ label, error, required, children, hint }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-slate-700">
                {label}
                {required ? <span className="text-red-600"> *</span> : null}
            </span>
            <span className="mt-1.5 block">{children}</span>
            {error ? (
                <span className="mt-1 block text-sm text-red-600" role="alert">
                    {error}
                </span>
            ) : hint ? (
                <span className="mt-1 block text-xs text-slate-500">{hint}</span>
            ) : null}
        </label>
    );
}

export const inputClass =
    'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:border-(--site-brand) focus:ring-4 focus:ring-(--site-ring) focus:outline-none';

/** Visually hidden field that bots fill in; the server ignores submissions that have it. */
export function Honeypot({ value, onChange }) {
    return (
        <div aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 overflow-hidden">
            <label>
                Company website
                <input type="text" name="company_website" tabIndex={-1} autoComplete="off" value={value} onChange={(event) => onChange(event.target.value)} />
            </label>
        </div>
    );
}

export function initials(name) {
    return String(name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}

/** Area and city from the address, e.g. "Kothrud, Pune". */
export function shortPlace(contact, fallback = null) {
    if (!contact.address) {
        return contact.city ?? fallback;
    }

    const parts = contact.address.split(',').map((part) => part.trim()).filter(Boolean);
    const city = contact.city?.trim();

    if (!city) {
        return parts.slice(-2).join(', ');
    }

    return [parts.filter((part) => part.toLowerCase() !== city.toLowerCase()).at(-1), city].filter(Boolean).join(', ');
}

/** The WhatsApp link with a custom pre-filled message (the business number stays the same). */
export function whatsappWith(url, text) {
    if (!url) {
        return null;
    }

    try {
        const link = new URL(url);
        link.searchParams.set('text', text);

        return link.toString();
    } catch {
        return url;
    }
}

import { createContext, useContext } from 'react';
import { fontFamily, heroStyle, radius } from '@/utils/websiteTheme';

/**
 * Shared state of a public tenant website: business details, theme and the cross-section
 * actions (e.g. "Book" on a service jumps to the booking section with that service chosen).
 */
export const SiteContext = createContext(null);

export function useSite() {
    return useContext(SiteContext);
}

export function siteTheme(template, primaryColor) {
    const color = primaryColor || '#4f46e5';

    return {
        color,
        font: fontFamily(template),
        radius: radius(template),
        hero: heroStyle(template, color),
    };
}

export function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export function Section({ id, tone = 'white', children, className = '' }) {
    return (
        <section id={id} className={`scroll-mt-20 ${tone === 'muted' ? 'bg-slate-50' : 'bg-white'}`}>
            <div className={`mx-auto max-w-5xl px-4 py-16 sm:px-6 sm:py-20 ${className}`}>{children}</div>
        </section>
    );
}

export function SectionHeading({ title, intro }) {
    const { theme } = useSite();

    return (
        <div className="mx-auto mb-10 max-w-2xl text-center">
            <h2 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{title}</h2>
            <div className="mx-auto mt-3 h-1 w-12 rounded-full" style={{ backgroundColor: theme.color }} />
            {intro ? <p className="mt-4 whitespace-pre-line text-slate-600">{intro}</p> : null}
        </div>
    );
}

export function Card({ children, className = '' }) {
    const { theme } = useSite();

    return (
        <div className={`border border-slate-100 bg-white p-5 shadow-sm ${className}`} style={{ borderRadius: theme.radius }}>
            {children}
        </div>
    );
}

/** Primary (filled) or secondary (outlined) call to action; renders a link when given href. */
export function ActionButton({ href, onClick, variant = 'primary', children, className = '', ...rest }) {
    const { theme } = useSite();
    const style =
        variant === 'primary'
            ? { backgroundColor: theme.color, color: '#ffffff', borderRadius: theme.radius }
            : { border: `1px solid ${theme.color}`, color: theme.color, borderRadius: theme.radius };
    const classes = `inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-semibold transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 ${className}`;

    return href ? (
        <a href={href} onClick={onClick} className={classes} style={style} {...rest}>
            {children}
        </a>
    ) : (
        <button type="button" onClick={onClick} className={classes} style={style} {...rest}>
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
            <span className="mt-1 block">{children}</span>
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
    'block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 shadow-sm focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none';

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

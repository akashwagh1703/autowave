/**
 * Turns a website template theme (config/catalog.php `website_templates.*.theme`)
 * plus the tenant's brand colour into styles. Shared by the public site and the
 * onboarding template preview so both always match.
 */

const FONTS = {
    sans: "'Inter', ui-sans-serif, system-ui, sans-serif",
    serif: "'Playfair Display', Georgia, 'Times New Roman', ui-serif, serif",
};

const RADII = { none: '0px', sm: '4px', md: '8px', lg: '16px' };

export function fontFamily(theme) {
    return FONTS[theme?.font] ?? FONTS.sans;
}

export function radius(theme) {
    return RADII[theme?.radius] ?? RADII.md;
}

/** Hero background and text colour for the template's hero style. */
export function heroStyle(theme, color) {
    switch (theme?.hero) {
        case 'gradient':
            return { background: `linear-gradient(135deg, ${color} 0%, #0f172a 130%)`, color: '#ffffff' };
        case 'dark':
            return { background: '#0f172a', color: '#ffffff', accent: color };
        case 'soft':
            return { background: `${color}14`, color: '#0f172a', accent: color };
        case 'solid':
            return { background: color, color: '#ffffff' };
        default:
            return { background: '#ffffff', color: '#0f172a', accent: color };
    }
}

/*
 * Layout personality of each template on the public site. The heading fonts are loaded in
 * app.blade.php (keep in sync). Unknown codes fall back to the template's hero style.
 *  - hero: split | cinematic | arch | editorial | panel
 *  - align: section headings centred or left-aligned
 *  - menu: services shown as a price list instead of cards
 *  - numbered: section eyebrows get "01", "02" …
 *  - dark: header, testimonials and footer use the dark surface
 */
const LAYOUTS = {
    modern: { hero: 'split', heading: "'Plus Jakarta Sans', 'Inter', ui-sans-serif, system-ui, sans-serif", headingWeight: 800, tracking: '-0.025em', align: 'left', menu: false, numbered: false, dark: false },
    premium: { hero: 'cinematic', heading: "'Playfair Display', Georgia, ui-serif, serif", headingWeight: 600, tracking: '-0.01em', align: 'center', menu: true, numbered: false, dark: true },
    elegant: { hero: 'arch', heading: "'Cormorant Garamond', 'Playfair Display', Georgia, ui-serif, serif", headingWeight: 600, tracking: '0', align: 'center', menu: true, numbered: false, dark: false },
    minimal: { hero: 'editorial', heading: "'Inter', ui-sans-serif, system-ui, sans-serif", headingWeight: 800, tracking: '-0.04em', align: 'left', menu: false, numbered: true, dark: false },
    corporate: { hero: 'panel', heading: "'Plus Jakarta Sans', 'Inter', ui-sans-serif, system-ui, sans-serif", headingWeight: 700, tracking: '-0.02em', align: 'left', menu: false, numbered: false, dark: false },
};

const HERO_FALLBACK = { gradient: 'modern', dark: 'premium', soft: 'elegant', plain: 'minimal', solid: 'corporate' };

export function siteLayout(template) {
    return LAYOUTS[template?.code] ?? LAYOUTS[HERO_FALLBACK[template?.hero]] ?? LAYOUTS.modern;
}

/** The brand colour mixed with transparency (0–1), for tints, rings and glows. */
export function alpha(color, amount) {
    return `color-mix(in srgb, ${color} ${Math.round(amount * 100)}%, transparent)`;
}

/** The brand colour mixed towards black (amount 0–1). */
export function shade(color, amount) {
    return `color-mix(in srgb, ${color} ${Math.round((1 - amount) * 100)}%, #000000)`;
}

/** White or near-black, whichever reads better on the given hex colour. */
export function readableOn(color) {
    const hex = String(color ?? '').replace('#', '');
    const full = hex.length === 3 ? hex.replace(/./g, '$&$&') : hex;

    if (!/^[0-9a-f]{6}$/i.test(full)) {
        return '#ffffff';
    }

    const [r, g, b] = [0, 2, 4].map((index) => {
        const channel = parseInt(full.slice(index, index + 2), 16) / 255;

        return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });
    const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    return luminance > 0.45 ? '#0f172a' : '#ffffff';
}

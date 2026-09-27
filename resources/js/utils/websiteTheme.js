/**
 * Turns a website template theme (config/catalog.php `website_templates.*.theme`)
 * plus the tenant's brand colour into styles. Shared by the public site and the
 * onboarding template preview so both always match.
 */

const FONTS = {
    sans: "'Inter', ui-sans-serif, system-ui, sans-serif",
    serif: "Georgia, 'Times New Roman', ui-serif, serif",
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

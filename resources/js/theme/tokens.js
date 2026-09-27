// AutoWave design tokens — the single JS source for brand values.
// Tailwind mirrors these in resources/css/app.css (@theme). Keep both in sync.

export const colors = {
    brand: {
        50: '#eef2ff',
        100: '#e0e7ff',
        500: '#6366f1',
        600: '#4f46e5',
        700: '#4338ca',
        900: '#312e81',
    },
    accent: {
        500: '#14b8a6',
        600: '#0d9488',
    },
    success: '#16a34a',
    warning: '#d97706',
    error: '#dc2626',
    info: '#0284c7',
};

export const radius = {
    control: 8,
    card: 12,
};

export const fontFamily =
    "'Inter', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol'";

// "revenue_today" -> "Revenue today"
export function humanize(code) {
    const text = String(code ?? '').replace(/[_-]+/g, ' ').trim();

    return text.charAt(0).toUpperCase() + text.slice(1);
}

// Server timestamps are ISO-8601 UTC; show them in the business's timezone.
export function formatDateTime(iso, timeZone, options = { dateStyle: 'medium', timeStyle: 'short' }) {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, { ...options, timeZone: timeZone || undefined }).format(new Date(iso));
}

export function formatDate(iso, timeZone) {
    return formatDateTime(iso, timeZone, { dateStyle: 'medium' });
}

const RELATIVE_UNITS = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
];

// "in 2 days", "3 hours ago", "now"
export function formatRelative(iso, now = Date.now()) {
    if (!iso) {
        return '';
    }

    const seconds = (new Date(iso).getTime() - now) / 1000;
    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of RELATIVE_UNITS) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return formatter.format(0, 'second');
}

// File sizes in binary units, as the storage allowance counts them: "820 KB", "4.2 MB", "1 GB".
export function formatBytes(bytes) {
    const value = Number(bytes) || 0;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(units.length - 1, Math.floor(Math.log(Math.max(value, 1)) / Math.log(1024)));
    const scaled = value / 1024 ** exponent;

    return `${exponent === 0 || scaled >= 10 ? Math.round(scaled) : Math.round(scaled * 10) / 10} ${units[exponent]}`;
}

export function formatMoney(value, currency) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'INR',
            maximumFractionDigits: 0,
        }).format(Number(value));
    } catch {
        return String(value);
    }
}

// Prices and order totals: paise/cents are kept ("₹249.50"), whole amounts stay short ("₹250").
export function formatPrice(value, currency) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const amount = Number(value);

    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'INR',
            minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
            maximumFractionDigits: 2,
        }).format(amount);
    } catch {
        return String(value);
    }
}

// ISO timestamp -> "YYYY-MM-DDTHH:mm" wall-clock time in the timezone (for datetime-local inputs).
export function toLocalInput(iso, timeZone) {
    if (!iso) {
        return '';
    }

    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: timeZone || undefined,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(new Date(iso))
            .map((part) => [part.type, part.value]),
    );

    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

export function isOverdue(iso, now = Date.now()) {
    return Boolean(iso) && new Date(iso).getTime() < now;
}

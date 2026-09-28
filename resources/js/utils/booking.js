// ISO weekday (1 = Monday) labels, matching the server's working-hours format.
export const WEEKDAYS = [
    { value: 1, short: 'Mon', label: 'Monday' },
    { value: 2, short: 'Tue', label: 'Tuesday' },
    { value: 3, short: 'Wed', label: 'Wednesday' },
    { value: 4, short: 'Thu', label: 'Thursday' },
    { value: 5, short: 'Fri', label: 'Friday' },
    { value: 6, short: 'Sat', label: 'Saturday' },
    { value: 7, short: 'Sun', label: 'Sunday' },
];

// 90 -> "1 h 30 min"
export function formatDuration(minutes) {
    const value = Number(minutes) || 0;
    const hours = Math.floor(value / 60);
    const rest = value % 60;

    if (hours === 0) {
        return `${rest} min`;
    }

    return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`;
}

// "2026-09-28" + 1 -> "2026-09-29" (calendar arithmetic on a plain date, no timezone involved)
export function addDays(date, days) {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + days));

    return next.toISOString().slice(0, 10);
}

// "2026-09-28" -> "Monday, 28 Sep 2026"
export function formatDay(date, options = { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' }) {
    const [year, month, day] = date.split('-').map(Number);

    return new Intl.DateTimeFormat(undefined, { ...options, timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day)));
}

// ISO instant -> "HH:mm" wall-clock time in the timezone
export function formatTime(iso, timeZone) {
    return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: timeZone || undefined }).format(
        new Date(iso),
    );
}

// ISO instant -> "YYYY-MM-DD" calendar date in the timezone
export function localDate(iso, timeZone) {
    return new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: timeZone || undefined }).format(new Date(iso));
}

// Minutes since local midnight of an ISO instant in the timezone (for positioning on a day grid)
export function minutesIntoDay(iso, timeZone) {
    const [hours, minutes] = formatTime(iso, timeZone).split(':').map(Number);

    return hours * 60 + minutes;
}

export const STATUS_STYLES = {
    pending: { chip: 'warning', block: 'border-amber-400 bg-amber-50 text-amber-900' },
    confirmed: { chip: 'primary', block: 'border-brand-500 bg-brand-50 text-brand-900' },
    completed: { chip: 'success', block: 'border-emerald-500 bg-emerald-50 text-emerald-900' },
    cancelled: { chip: 'default', block: 'border-slate-300 bg-slate-100 text-slate-500 line-through' },
    no_show: { chip: 'error', block: 'border-red-300 bg-red-50 text-red-800 line-through' },
};

export async function getJson(url, params = {}) {
    const query = new URLSearchParams(
        Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    ).toString();
    const response = await fetch(query ? `${url}?${query}` : url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Request failed (${response.status})`);
    }

    return response.json();
}

/** POST JSON with Laravel's XSRF cookie; rejects with { status, errors } on failure. */
export async function postJson(url, body) {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw { status: response.status, errors: data.errors ?? {} };
    }

    return data;
}

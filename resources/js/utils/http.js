export async function getJson(url, params = {}) {
    const query = new URLSearchParams(
        Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    ).toString();
    const response = await fetch(query ? `${url}?${query}` : url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const error = new Error(`Request failed (${response.status})`);
        error.status = response.status;
        error.errors = data.errors ?? {};
        throw error;
    }

    return response.json();
}

/** POST JSON with Laravel's XSRF cookie; rejects with { status, message, errors } on failure. */
export async function postJson(url, body = {}) {
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
        throw { status: response.status, message: data.message ?? null, reason: data.reason ?? null, errors: data.errors ?? {} };
    }

    return data;
}

/** A readable message for a postJson() failure. */
export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
    if (error?.status === 429) {
        return 'Too many requests. Please wait a minute and try again.';
    }

    const firstError = Object.values(error?.errors ?? {})[0];

    return (Array.isArray(firstError) ? firstError[0] : firstError) || error?.message || fallback;
}

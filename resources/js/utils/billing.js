import { formatPrice } from '@/utils/format';

// AutoWave plan amounts are kept in paise.
export function rupees(paise) {
    return formatPrice((Number(paise) || 0) / 100, 'INR');
}

export const STATE_COLORS = {
    trial: 'info',
    active: 'success',
    unlimited: 'success',
    due: 'warning',
    read_only: 'error',
    locked: 'error',
};

export const PAYMENT_COLORS = {
    pending: 'warning',
    approved: 'success',
    rejected: 'error',
    cancelled: 'default',
    initiated: 'info',
    expired: 'default',
};

export const PAYMENT_STATUS_LABELS = {
    pending: 'Waiting for approval',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Withdrawn',
    initiated: 'Checkout open',
    expired: 'Not completed',
};

/** Plan limits in everyday words, for the public pricing page. */
export function plainLimitLabel(key, value) {
    const unlimited = value === null || value === undefined;

    switch (key) {
        case 'members':
            return unlimited ? 'Logins for all your staff' : `${value} ${value === 1 ? 'person' : 'people'} can log in`;
        case 'storage_mb':
            return unlimited ? 'Unlimited space for photos and files' : `${limitLabel(key, value).replace(' storage', '')} for photos and files`;
        case 'ai_tokens':
            return unlimited ? 'Unlimited AI help' : `AI help: ${new Intl.NumberFormat('en-IN').format(value)} credits a month`;
        case 'automations':
            return unlimited ? 'Unlimited automatic reminders and follow-ups' : `${value} automatic reminders and follow-ups`;
        case 'instagram':
            return value ? 'Instagram messages too' : 'WhatsApp and email messages (no Instagram)';
        default:
            return limitLabel(key, value);
    }
}

export function limitLabel(key, value) {
    if (key === 'instagram') {
        return value ? 'Instagram inbox' : 'No Instagram';
    }

    if (value === null || value === undefined) {
        return { members: 'Unlimited team members', automations: 'Unlimited active automations' }[key] ?? 'Unlimited';
    }

    switch (key) {
        case 'members':
            return `${value} team ${value === 1 ? 'member' : 'members'}`;
        case 'storage_mb':
            return value >= 1024 ? `${Math.round((value / 1024) * 10) / 10} GB storage` : `${value} MB storage`;
        case 'ai_tokens':
            return `${new Intl.NumberFormat('en-IN').format(value)} AI tokens a month`;
        case 'automations':
            return `${value} active automations`;
        default:
            return String(value);
    }
}

export const LIMIT_ORDER = ['members', 'storage_mb', 'ai_tokens', 'automations', 'instagram'];

export function todayInIndia() {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date());
}

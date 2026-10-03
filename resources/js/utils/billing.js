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
};

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

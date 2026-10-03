import { Link, usePage } from '@inertiajs/react';
import { formatDate } from '@/utils/format';

/** The plan banner on every business page: trial ending, payment due, read-only or locked (shared prop `billing`). */
export default function BillingBanner() {
    const { billing, tenant } = usePage().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    if (!billing || billing.unlimited || currentPath.startsWith('/settings/billing')) {
        return null;
    }

    const timezone = tenant?.timezone;
    let tone = 'bg-sky-50 text-sky-900 border-sky-200';
    let message = null;

    if (billing.pending) {
        if (billing.state === 'trial' || billing.state === 'active') {
            return null;
        }
        message = 'Your payment is being checked. Everything keeps working meanwhile.';
    } else if (billing.state === 'trial' && billing.days_left <= 7) {
        message = `Your free trial ends in ${billing.days_left} ${billing.days_left === 1 ? 'day' : 'days'} (${formatDate(billing.ends_at, timezone)}).`;
    } else if (billing.state === 'active' && billing.days_left <= 7) {
        message = `Your ${billing.plan} plan ends in ${billing.days_left} ${billing.days_left === 1 ? 'day' : 'days'}.`;
    } else if (billing.state === 'due') {
        tone = 'bg-amber-50 text-amber-900 border-amber-200';
        message = billing.enforced
            ? `Your ${billing.is_trial ? 'trial' : 'plan'} has ended. Pay before ${formatDate(billing.read_only_at, timezone)} to avoid going read-only.`
            : `Your ${billing.is_trial ? 'trial' : 'plan'} has ended.`;
    } else if (billing.state === 'read_only' || billing.state === 'locked') {
        tone = 'bg-red-50 text-red-900 border-red-200';
        message = !billing.enforced
            ? `Your ${billing.is_trial ? 'trial' : 'plan'} has ended.`
            : billing.state === 'read_only'
              ? 'Your business is read-only until you pay. Your team can view everything but cannot make changes.'
              : 'Your business is locked until you pay.';
    }

    if (!message) {
        return null;
    }

    return (
        <div className={`border-b px-4 py-2 text-center text-sm print:hidden ${tone}`} role="status">
            {message}{' '}
            {billing.can_manage ? (
                <Link href="/settings/billing" className="font-semibold underline">
                    {billing.pending ? 'View billing' : 'Choose a plan'}
                </Link>
            ) : (
                <span>Ask the business owner to pay.</span>
            )}
        </div>
    );
}

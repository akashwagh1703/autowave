import { usePage } from '@inertiajs/react';

/**
 * AI helpers for the current user: `enabled` when the business has the AI module and the user may use
 * it; `available` when it can be used right now (not switched off, set up, within the monthly allowance).
 */
export default function useAi() {
    const { ai } = usePage().props;

    return {
        enabled: Boolean(ai),
        available: Boolean(ai?.available),
        message: ai?.message ?? null,
        reason: ai?.reason ?? null,
    };
}

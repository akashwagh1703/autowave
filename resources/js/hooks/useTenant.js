import { usePage } from '@inertiajs/react';

export default function useTenant() {
    const { tenant, permissions } = usePage().props;

    return {
        tenant,
        timezone: tenant?.timezone,
        currency: tenant?.currency,
        hasModule: (code) => Boolean(tenant?.modules?.includes(code)),
        can: (permission) => permissions.includes(permission),
    };
}

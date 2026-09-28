import { usePage } from '@inertiajs/react';

export default function useTenant() {
    const { tenant, permissions } = usePage().props;

    return {
        tenant,
        timezone: tenant?.timezone,
        currency: tenant?.currency,
        // What the business calls its bookable resources: Staff, Doctor, Turf…
        resourceLabel: tenant?.resource_label ?? { singular: 'Staff', plural: 'Staff' },
        hasModule: (code) => Boolean(tenant?.modules?.includes(code)),
        hasEngine: (code) => Boolean(tenant?.engines?.includes(code)),
        can: (permission) => permissions.includes(permission),
    };
}

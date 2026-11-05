import { Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import Tooltip from '@mui/material/Tooltip';
import LockIcon from '@mui/icons-material/Lock';
import AppLayout from '@/layouts/AppLayout';
import useTenant from '@/hooks/useTenant';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { indexByCode, lockedModules, withDependencies } from '@/components/onboarding/modules';
import { humanize } from '@/utils/format';

function Detail({ label, value }) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
            <dd className="mt-1 text-sm text-slate-900">{value || '—'}</dd>
        </div>
    );
}

export default function Settings({ business, branding, profile, website, domains, modules, canUpdate }) {
    const { hasModule, hasEngine, can } = useTenant();
    const flash = usePage().props.flash ?? {};

    const modulesByCode = useMemo(() => indexByCode(modules), [modules]);
    const required = useMemo(() => modules.filter((module) => module.required).map((module) => module.code), [modules]);
    const initialSelected = useMemo(() => modules.filter((module) => module.enabled).map((module) => module.code), [modules]);

    const form = useForm({ modules: initialSelected });
    const locked = lockedModules(form.data.modules, required, modulesByCode);
    const dirty =
        form.data.modules.length !== initialSelected.length ||
        form.data.modules.some((code) => !initialSelected.includes(code));

    const toggle = (code) => {
        if (!canUpdate) {
            return;
        }

        if (form.data.modules.includes(code)) {
            if (!locked[code]) {
                form.setData(
                    'modules',
                    form.data.modules.filter((other) => other !== code),
                );
            }
            return;
        }

        form.setData('modules', withDependencies([...form.data.modules, code], modulesByCode));
    };

    const groups = modules.reduce((result, module) => {
        (result[module.type] ??= []).push(module);
        return result;
    }, {});

    const save = (event) => {
        event.preventDefault();
        form.put('/settings/modules', {
            preserveScroll: true,
            onSuccess: (page) => {
                const next = (page.props.modules ?? []).filter((module) => module.enabled).map((module) => module.code);
                form.setData('modules', next);
            },
        });
    };

    return (
        <AppLayout title="Settings">
            <PageHeader
                title="Business settings"
                description={canUpdate ? 'Business name, contact details and branding are edited under Website.' : 'You have view-only access.'}
                actions={
                    <>
                        {can('billing.view') ? (
                            <Button component={Link} href="/settings/billing" variant="outlined">
                                Billing
                            </Button>
                        ) : null}
                        {hasModule('website') && can('website.view') ? (
                            <Button component={Link} href="/website/details" variant="outlined">
                                Business details
                            </Button>
                        ) : null}
                        {hasEngine('booking') ? (
                            <Button component={Link} href="/settings/booking" variant="outlined">
                                Booking settings
                            </Button>
                        ) : null}
                        {hasEngine('commerce') ? (
                            <Button component={Link} href="/settings/commerce" variant="outlined">
                                Order settings
                            </Button>
                        ) : null}
                        {hasModule('loyalty') ? (
                            <Button component={Link} href="/settings/loyalty" variant="outlined">
                                Loyalty
                            </Button>
                        ) : null}
                        {hasEngine('food') ? (
                            <Button component={Link} href="/settings/food" variant="outlined">
                                Reservation settings
                            </Button>
                        ) : null}
                        {hasEngine('education') ? (
                            <Button component={Link} href="/settings/education" variant="outlined">
                                Coaching settings
                            </Button>
                        ) : null}
                        {hasModule('leads') ? (
                            <Button component={Link} href="/settings/crm" variant="outlined">
                                CRM settings
                            </Button>
                        ) : null}
                        {hasModule('messaging') ? (
                            <>
                                <Button component={Link} href="/settings/messaging" variant="outlined">
                                    Messaging
                                </Button>
                                <Button component={Link} href="/settings/whatsapp-assistant" variant="outlined">
                                    WhatsApp assistant
                                </Button>
                            </>
                        ) : null}
                        {hasModule('ai') ? (
                            <Button component={Link} href="/settings/ai" variant="outlined">
                                AI
                            </Button>
                        ) : null}
                    </>
                }
            />

            {flash.success ? (
                <p className="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{flash.success}</p>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card variant="outlined">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Business</h2>
                        <dl className="mt-4 grid grid-cols-2 gap-4">
                            <Detail label="Name" value={business.name} />
                            <Detail label="Workspace ID" value={business.slug} />
                            <Detail
                                label="Business type"
                                value={`${business.business_type ?? '—'} (v${business.business_type_version ?? '—'})`}
                            />
                            <Detail label="Timezone" value={business.timezone} />
                            <Detail label="Currency" value={business.currency} />
                            <Detail label="Language" value={business.locale} />
                        </dl>

                        <h2 className="mt-6 font-semibold text-slate-900">Contact details</h2>
                        <dl className="mt-4 grid grid-cols-2 gap-4">
                            <Detail label="Phone" value={profile.phone} />
                            <Detail label="Email" value={profile.email} />
                            <Detail label="City" value={profile.city} />
                            <Detail label="Address" value={profile.address} />
                            <div className="col-span-2">
                                <Detail label="Description" value={profile.description} />
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Branding</h2>
                        <dl className="mt-4 grid grid-cols-2 gap-4">
                            <Detail label="Display name" value={branding.business_name} />
                            <Detail label="Tagline" value={branding.tagline} />
                            <div>
                                <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">Primary colour</dt>
                                <dd className="mt-1 flex items-center gap-2 text-sm text-slate-900">
                                    <span
                                        className="inline-block h-4 w-4 rounded border border-slate-200"
                                        style={{ backgroundColor: branding.primary_color }}
                                    />
                                    {branding.primary_color}
                                </dd>
                            </div>
                        </dl>

                        <div className="mt-6 flex items-center justify-between gap-2">
                            <h2 className="font-semibold text-slate-900">Website</h2>
                            {website && hasModule('website') && can('website.view') ? (
                                <Button component={Link} href="/website" size="small">
                                    Manage website
                                </Button>
                            ) : null}
                        </div>
                        {website ? (
                            <dl className="mt-4 grid grid-cols-2 gap-4">
                                <Detail label="Template" value={website.template} />
                                <div>
                                    <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">Status</dt>
                                    <dd className="mt-1">
                                        <StatusChip status={website.status} />
                                    </dd>
                                </div>
                                <div className="col-span-2">
                                    <Detail
                                        label="Sections"
                                        value={website.sections
                                            .filter((section) => section.enabled)
                                            .map((section) => humanize(section.type))
                                            .join(' · ')}
                                    />
                                </div>
                            </dl>
                        ) : (
                            <p className="mt-2 text-sm text-slate-600">No website configured.</p>
                        )}

                        <h2 className="mt-6 font-semibold text-slate-900">Domains</h2>
                        <ul className="mt-3 space-y-2">
                            {domains.map((domain) => (
                                <li key={domain.domain} className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="font-medium text-slate-900">{domain.domain}</span>
                                    {domain.is_primary ? <Chip label="Primary" size="small" color="primary" /> : null}
                                    <StatusChip status={domain.status} />
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </div>

            <Card variant="outlined" className="mt-4" component="form" onSubmit={save}>
                <CardContent>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 className="font-semibold text-slate-900">Modules</h2>
                            <p className="mt-1 text-sm text-slate-600">
                                {canUpdate
                                    ? 'Turn features on or off any time. Required features stay locked; turning one on also enables what it needs.'
                                    : 'Features included with your business. Ask an owner if you need a change.'}
                            </p>
                        </div>
                        {canUpdate ? (
                            <Button type="submit" variant="contained" disabled={!dirty || form.processing}>
                                {form.processing ? 'Saving…' : 'Save modules'}
                            </Button>
                        ) : null}
                    </div>

                    {Object.entries(groups).map(([type, items]) => (
                        <section key={type} className="mt-5">
                            <h3 className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">{humanize(type)}</h3>
                            <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {items.map((module) => {
                                    const checked = form.data.modules.includes(module.code);
                                    const reason = checked ? locked[module.code] : null;
                                    const disabled = !canUpdate || Boolean(reason);

                                    return (
                                        <li key={module.code}>
                                            <label
                                                className={`flex h-full items-start gap-2 rounded-lg border p-3 ${
                                                    checked ? 'border-brand-100 bg-brand-50' : 'border-slate-200 bg-white'
                                                } ${disabled ? 'cursor-default' : 'cursor-pointer'}`}
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    disabled={disabled}
                                                    onChange={() => toggle(module.code)}
                                                    size="small"
                                                    className="-mt-1 -ml-1"
                                                    slotProps={{ input: { 'aria-describedby': `settings-module-${module.code}` } }}
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex flex-wrap items-center gap-1.5 text-sm font-medium text-slate-900">
                                                        {module.name}
                                                        {module.recommended ? (
                                                            <Chip label="Recommended" size="small" color="primary" variant="outlined" className="h-5 text-[11px]" />
                                                        ) : null}
                                                        {reason ? (
                                                            <Tooltip title={reason}>
                                                                <LockIcon className="text-slate-400" sx={{ fontSize: 14 }} aria-label={reason} />
                                                            </Tooltip>
                                                        ) : null}
                                                    </span>
                                                    <span id={`settings-module-${module.code}`} className="mt-0.5 block text-xs text-slate-600">
                                                        {reason ?? module.description}
                                                    </span>
                                                </span>
                                            </label>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}

                    {form.errors.modules ? <p className="mt-3 text-sm text-red-600">{form.errors.modules}</p> : null}
                </CardContent>
            </Card>
        </AppLayout>
    );
}

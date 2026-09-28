import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import AppLayout from '@/layouts/AppLayout';
import useTenant from '@/hooks/useTenant';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
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
    const { hasModule, hasEngine } = useTenant();

    return (
        <AppLayout title="Settings">
            <PageHeader
                title="Business settings"
                description={canUpdate ? 'Business details editing arrives in a later release.' : 'You have view-only access.'}
                actions={
                    <>
                        {hasEngine('booking') ? (
                            <Button component={Link} href="/settings/booking" variant="outlined">
                                Booking settings
                            </Button>
                        ) : null}
                        {hasModule('leads') ? (
                            <Button component={Link} href="/settings/crm" variant="outlined">
                                CRM settings
                            </Button>
                        ) : null}
                    </>
                }
            />

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

                        <h2 className="mt-6 font-semibold text-slate-900">Website</h2>
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

            <Card variant="outlined" className="mt-4">
                <CardContent>
                    <h2 className="font-semibold text-slate-900">Modules</h2>
                    <ul className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {modules.map((module) => (
                            <li
                                key={module.code}
                                className={`rounded-lg border p-3 ${module.enabled ? 'border-brand-100 bg-brand-50' : 'border-slate-200'}`}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-medium text-slate-900">{module.name}</span>
                                    <StatusChip status={module.enabled ? 'active' : 'disabled'} />
                                </div>
                                <p className="mt-1 text-xs text-slate-600">{module.description}</p>
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>
        </AppLayout>
    );
}

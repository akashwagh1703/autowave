import { Link } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';
import { formatMoney, humanize } from '@/utils/format';

const widgetLabels = { pending_followups: 'Pending follow-ups' };

function Widget({ code, metric, currency }) {
    const value = metric ? (metric.type === 'currency' ? formatMoney(metric.value, currency) : metric.value) : '—';
    const body = (
        <CardContent>
            <p className="text-sm text-slate-500">{widgetLabels[code] ?? humanize(code)}</p>
            <p className={`mt-2 text-2xl font-semibold ${metric ? 'text-slate-900' : 'text-slate-300'}`}>{value}</p>
            {metric?.hint ? <p className="mt-1 text-xs text-slate-500">{metric.hint}</p> : null}
        </CardContent>
    );

    return (
        <Card variant="outlined">
            {metric?.href ? (
                <CardActionArea component={Link} href={metric.href}>
                    {body}
                </CardActionArea>
            ) : (
                body
            )}
        </Card>
    );
}

export default function Dashboard({ workspace, modules, engines, widgets, metrics }) {
    const { currency } = useTenant();

    return (
        <AppLayout title="Dashboard">
            <PageHeader
                title={workspace.name}
                description={workspace.business_type}
                actions={
                    workspace.website_url && modules.includes('website') ? (
                        <Button
                            variant="outlined"
                            href={workspace.website_url}
                            target="_blank"
                            rel="noreferrer"
                            endIcon={<OpenInNewIcon fontSize="small" />}
                        >
                            View website
                        </Button>
                    ) : null
                }
            />

            <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {widgets.map((widget) => (
                    <Widget key={widget} code={widget} metric={metrics[widget]} currency={currency} />
                ))}
            </section>
            <p className="mt-3 text-xs text-slate-500">
                Widgets come from your business type. Figures showing “—” go live as their modules are built.
            </p>

            <section className="mt-8 grid gap-4 md:grid-cols-2">
                <Card variant="outlined">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Engines</h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {engines.map((engine) => (
                                <Chip key={engine} label={humanize(engine)} color="secondary" variant="outlined" />
                            ))}
                        </div>
                    </CardContent>
                </Card>
                <Card variant="outlined">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Enabled modules</h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {modules.map((module) => (
                                <Chip key={module} label={humanize(module)} color="primary" variant="outlined" />
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </section>
        </AppLayout>
    );
}

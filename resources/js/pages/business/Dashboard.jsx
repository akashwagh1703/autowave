import { Link } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import RadioButtonUncheckedIcon from '@mui/icons-material/RadioButtonUnchecked';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';
import { formatMoney, humanize } from '@/utils/format';

const widgetLabels = { pending_followups: 'Pending follow-ups', no_shows: 'No-shows', fees_due: 'Fees overdue', demo_classes: 'Demo classes' };

function Widget({ code, metric, currency }) {
    const value = metric ? (metric.type === 'currency' ? formatMoney(metric.value, currency) : metric.value) : '—';
    const body = (
        <CardContent>
            <p className="text-sm text-slate-500">{widgetLabels[code] ?? humanize(code)}</p>
            {metric?.type === 'list' ? (
                metric.items?.length ? (
                    <ol className="mt-2 space-y-1 text-sm">
                        {metric.items.map((item, index) => (
                            <li key={`${item.label}-${index}`} className="flex justify-between gap-2">
                                <span className="truncate text-slate-900">{item.label}</span>
                                <span className="shrink-0 text-slate-500">{item.value}</span>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="mt-2 text-sm text-slate-400">Nothing yet</p>
                )
            ) : (
                <p className={`mt-2 text-2xl font-semibold ${metric ? 'text-slate-900' : 'text-slate-300'}`}>{value}</p>
            )}
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

export default function Dashboard({ workspace, modules, engines, widgets, metrics, goLive }) {
    const { currency } = useTenant();
    const unfinished = goLive?.total > 0 && goLive.done < goLive.total;

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

            {unfinished ? (
                <Card variant="outlined" className="mb-6 border-brand-200 bg-brand-50/40">
                    <CardContent>
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="font-semibold text-slate-900">Get ready for customers</h2>
                            <p className="text-sm text-slate-600">
                                {goLive.done} of {goLive.total} done
                            </p>
                        </div>
                        <ul className="mt-4 space-y-2">
                            {goLive.items.map((item) => (
                                <li key={item.key}>
                                    <Link
                                        href={item.href ?? '/dashboard'}
                                        className={`flex items-center gap-2 text-sm ${item.done ? 'text-slate-500 line-through' : 'font-medium text-slate-900 hover:text-brand-700'}`}
                                    >
                                        {item.done ? <CheckCircleIcon className="text-emerald-600" sx={{ fontSize: 18 }} /> : <RadioButtonUncheckedIcon className="text-slate-400" sx={{ fontSize: 18 }} />}
                                        {item.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            ) : null}

            <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {widgets.map((widget) => (
                    <Widget key={widget} code={widget} metric={metrics[widget]} currency={currency} />
                ))}
            </section>
            <p className="mt-3 text-xs text-slate-500">Today’s figures for your business. A dash means nothing recorded yet.</p>

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

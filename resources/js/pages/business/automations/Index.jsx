import { Link, router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Switch from '@mui/material/Switch';
import Tooltip from '@mui/material/Tooltip';
import AddIcon from '@mui/icons-material/Add';
import AutoModeIcon from '@mui/icons-material/AutoMode';
import HistoryIcon from '@mui/icons-material/History';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatRelative } from '@/utils/format';

const statuses = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'On' },
    { value: 'paused', label: 'Paused' },
];

function Stat({ label, value, tone = 'text-slate-900', href }) {
    const body = (
        <>
            <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tone}`}>{value}</p>
        </>
    );

    return (
        <Card variant="outlined">
            <CardContent>{href ? <Link href={href}>{body}</Link> : body}</CardContent>
        </Card>
    );
}

export default function Index({ automations, filters: initialFilters, counts, stats }) {
    const { can } = useTenant();
    const { filters, apply } = useFilters('/automations', initialFilters);
    const [toggling, setToggling] = useState(null);
    const canUpdate = can('automation.update');

    const toggle = (automation) =>
        router.patch(`/automations/${automation.id}/toggle`, { is_active: !automation.is_active }, {
            preserveScroll: true,
            onStart: () => setToggling(automation.id),
            onFinish: () => setToggling(null),
        });

    return (
        <AppLayout title="Automations">
            <PageHeader
                title="Automations"
                description="Follow up, remind and notify automatically when something happens in your business."
                actions={
                    <>
                        <Button component={Link} href="/automations/runs" color="inherit" startIcon={<HistoryIcon />}>
                            Run history
                        </Button>
                        {can('automation.create') ? (
                            <Button component={Link} href="/automations/create" variant="contained" startIcon={<AddIcon />}>
                                New automation
                            </Button>
                        ) : null}
                    </>
                }
            />

            <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat label="Runs (7 days)" value={stats.runs_7d} href="/automations/runs" />
                <Stat label="Completed (7 days)" value={stats.completed_7d} tone="text-emerald-700" href="/automations/runs?status=completed" />
                <Stat label="In progress" value={stats.in_progress} tone="text-sky-700" href="/automations/runs?status=waiting" />
                <Stat label="Failed (7 days)" value={stats.failed_7d} tone={stats.failed_7d ? 'text-red-700' : 'text-slate-900'} href="/automations/runs?status=failed" />
            </div>

            <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label="Filter automations">
                {statuses.map((status) => (
                    <button
                        key={status.value}
                        type="button"
                        onClick={() => apply({ status: status.value })}
                        className={`rounded-lg border px-3 py-1.5 text-sm transition ${filters.status === status.value ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'}`}
                    >
                        {status.label}
                        {status.value === 'all' ? ` · ${counts.all}` : status.value === 'active' ? ` · ${counts.active}` : ` · ${counts.all - counts.active}`}
                    </button>
                ))}
            </div>

            {automations.length === 0 ? (
                <EmptyState
                    icon={AutoModeIcon}
                    title={filters.status === 'all' ? 'No automations yet' : 'Nothing here'}
                    description={filters.status === 'all' ? 'Create one to follow up with leads or remind customers automatically.' : 'Try another filter.'}
                    action={
                        filters.status === 'all' && can('automation.create') ? (
                            <Button component={Link} href="/automations/create" variant="contained" startIcon={<AddIcon />}>
                                New automation
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    {automations.map((automation) => (
                        <Card key={automation.id} variant="outlined" className={automation.is_active ? '' : 'opacity-80'}>
                            <CardContent className="space-y-3">
                                <div className="flex items-start gap-3">
                                    <div className="min-w-0 flex-1">
                                        <Link href={`/automations/${automation.id}`} className="font-semibold text-slate-900 hover:text-brand-700">
                                            {automation.name}
                                        </Link>
                                        <p className="text-sm text-slate-600">
                                            When: <span className="font-medium text-slate-800">{automation.trigger_label}</span>
                                            {!automation.trigger_available ? <span className="ml-1 text-amber-700">(not available)</span> : null}
                                        </p>
                                    </div>
                                    <Tooltip title={canUpdate ? (automation.is_active ? 'Pause' : 'Turn on') : ''}>
                                        <span>
                                            <Switch
                                                checked={automation.is_active}
                                                disabled={!canUpdate || toggling === automation.id || (!automation.is_active && !automation.trigger_available)}
                                                onChange={() => toggle(automation)}
                                                slotProps={{ input: { 'aria-label': `${automation.is_active ? 'Pause' : 'Turn on'} ${automation.name}` } }}
                                            />
                                        </span>
                                    </Tooltip>
                                </div>

                                <ol className="space-y-1 text-sm text-slate-700">
                                    {automation.steps.map((step, index) => (
                                        <li key={index} className="flex gap-2">
                                            <span className="text-slate-400">{index + 1}.</span>
                                            <span className="truncate">{step.summary}</span>
                                        </li>
                                    ))}
                                </ol>

                                <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    <Chip size="small" variant="outlined" color={automation.is_active ? 'success' : 'default'} label={automation.is_active ? 'On' : 'Paused'} />
                                    <span>{automation.runs_count} run{automation.runs_count === 1 ? '' : 's'}</span>
                                    {automation.active_runs_count ? <span>· {automation.active_runs_count} in progress</span> : null}
                                    {automation.failed_runs_count ? <span className="text-red-700">· {automation.failed_runs_count} failed</span> : null}
                                    {automation.last_run_at ? <span>· last {formatRelative(automation.last_run_at)}</span> : null}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}

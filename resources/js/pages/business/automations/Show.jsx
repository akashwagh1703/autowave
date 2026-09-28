import { Link, router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import BoltIcon from '@mui/icons-material/Bolt';
import EditIcon from '@mui/icons-material/EditOutlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ConfirmDialog from '@/components/ConfirmDialog';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import RunsTable from '@/modules/automations/RunsTable';
import useTenant from '@/hooks/useTenant';

export default function Show({ automation, runs }) {
    const { can, timezone } = useTenant();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [processing, setProcessing] = useState(false);

    const options = { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) };
    const toggle = () => router.patch(`/automations/${automation.id}/toggle`, { is_active: !automation.is_active }, options);
    const destroy = () => router.delete(`/automations/${automation.id}`, options);

    return (
        <AppLayout title={automation.name}>
            <Button component={Link} href="/automations" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Automations
            </Button>
            <PageHeader
                title={automation.name}
                description={automation.description}
                actions={
                    <>
                        {can('automation.update') ? (
                            <>
                                <Button color="inherit" disabled={processing || (!automation.is_active && !automation.trigger_available)} onClick={toggle}>
                                    {automation.is_active ? 'Pause' : 'Turn on'}
                                </Button>
                                <Button component={Link} href={`/automations/${automation.id}/edit`} variant="outlined" startIcon={<EditIcon />}>
                                    Edit
                                </Button>
                            </>
                        ) : null}
                        {can('automation.delete') ? (
                            <Button color="error" onClick={() => setConfirmDelete(true)}>
                                Delete
                            </Button>
                        ) : null}
                    </>
                }
            />

            <div className="grid gap-6 lg:grid-cols-[22rem_1fr]">
                <Card variant="outlined" className="self-start">
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap gap-2">
                            <Chip size="small" variant="outlined" color={automation.is_active ? 'success' : 'default'} label={automation.is_active ? 'On' : 'Paused'} />
                            {automation.once_per_subject ? <Chip size="small" variant="outlined" label="Once per record" /> : null}
                            {automation.template_key ? <Chip size="small" variant="outlined" label="Default" /> : null}
                        </div>
                        <ol className="space-y-3">
                            <li className="flex gap-2 text-sm">
                                <BoltIcon className="text-brand-600" fontSize="small" />
                                <span>
                                    When <span className="font-medium">{automation.trigger_label}</span>
                                    {!automation.trigger_available ? <span className="block text-xs text-amber-700">Not available for your business right now.</span> : null}
                                </span>
                            </li>
                            {automation.steps.map((step, index) => (
                                <li key={index} className="flex gap-2 text-sm text-slate-700">
                                    <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{index + 1}</span>
                                    <span className="break-words">{step.summary}</span>
                                </li>
                            ))}
                        </ol>
                        <p className="text-xs text-slate-500">
                            {automation.runs_count} run{automation.runs_count === 1 ? '' : 's'} · {automation.active_runs_count} in progress · {automation.failed_runs_count} failed
                        </p>
                    </CardContent>
                </Card>

                <div>
                    <h2 className="mb-2 font-semibold text-slate-900">Recent runs</h2>
                    {runs.data.length === 0 ? (
                        <EmptyState title="No runs yet" description={automation.is_active ? `It runs the next time: ${automation.trigger_label.toLowerCase()}.` : 'Turn it on to start running.'} />
                    ) : (
                        <Card variant="outlined">
                            <RunsTable runs={runs.data} timezone={timezone} showAutomation={false} />
                        </Card>
                    )}
                    <Pagination meta={runs.meta} noun="runs" />
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${automation.name}?`}
                description="It stops immediately; runs in progress are cancelled at their next step. Run history is kept."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={destroy}
                onClose={() => setConfirmDelete(false)}
            />
        </AppLayout>
    );
}

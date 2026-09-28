import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ConfirmDialog from '@/components/ConfirmDialog';
import RunStatusChip from '@/modules/automations/RunStatusChip';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, humanize } from '@/utils/format';

const levelColors = { info: 'bg-slate-300', warning: 'bg-amber-500', error: 'bg-red-600' };

export default function RunShow({ run }) {
    const { can, timezone } = useTenant();
    const { errors } = usePage().props;
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [processing, setProcessing] = useState(false);
    const canUpdate = can('automation.update');
    const options = { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => { setProcessing(false); setConfirmCancel(false); } };
    const when = (iso) => formatDateTime(iso, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });

    return (
        <AppLayout title={`Run #${run.id}`}>
            <Button component={Link} href="/automations/runs" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Run history
            </Button>
            <PageHeader
                title={`Run #${run.id}`}
                description={
                    <>
                        {run.automation ? (
                            run.automation.deleted ? `${run.automation.name} (deleted)` : <Link href={`/automations/${run.automation.id}`} className="text-brand-700 hover:underline">{run.automation.name}</Link>
                        ) : null}
                        {' · '}
                        {run.trigger_label} ·{' '}
                        {run.subject.url ? (
                            <Link href={run.subject.url} className="text-brand-700 hover:underline">
                                {run.subject.label}
                            </Link>
                        ) : (
                            <span>{run.subject.label}{run.subject.deleted ? ' (deleted)' : ''}</span>
                        )}
                    </>
                }
                actions={
                    canUpdate ? (
                        <>
                            {run.status === 'failed' ? (
                                <Button variant="contained" disabled={processing} onClick={() => router.post(`/automations/runs/${run.id}/retry`, {}, options)}>
                                    Retry
                                </Button>
                            ) : null}
                            {!run.is_final ? (
                                <Button color="error" disabled={processing} onClick={() => setConfirmCancel(true)}>
                                    Cancel run
                                </Button>
                            ) : null}
                        </>
                    ) : null
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-3 text-sm text-slate-600">
                <RunStatusChip status={run.status} label={run.status_label} size="medium" />
                <span>Started {when(run.started_at ?? run.created_at)}</span>
                {run.completed_at ? <span>· Finished {when(run.completed_at)}</span> : null}
                {run.depth > 0 ? <Chip size="small" variant="outlined" label={`Started by another automation (level ${run.depth + 1})`} /> : null}
            </div>

            {errors.run ? (
                <Alert severity="warning" className="mb-4">
                    {errors.run}
                </Alert>
            ) : null}
            {run.error ? (
                <Alert severity="error" className="mb-4">
                    {run.error}
                </Alert>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-2">
                <Card variant="outlined" className="self-start">
                    <CardContent>
                        <h2 className="mb-3 font-semibold text-slate-900">Steps</h2>
                        <ol className="space-y-3">
                            {run.steps.map((step) => (
                                <li key={step.index} className="flex gap-3">
                                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{step.index + 1}</span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-slate-800">{step.summary}</p>
                                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                            <RunStatusChip status={step.status} />
                                            {step.status === 'pending' && step.run_at ? <span>Due {when(step.run_at)}</span> : null}
                                            {step.attempts > 1 ? <span>{step.attempts} attempts</span> : null}
                                            {step.finished_at ? <span>{when(step.finished_at)}</span> : null}
                                        </div>
                                        {step.error ? <p className="mt-1 text-xs text-red-700">{step.error}</p> : null}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>

                <Card variant="outlined" className="self-start">
                    <CardContent>
                        <h2 className="mb-3 font-semibold text-slate-900">Log</h2>
                        <ol className="space-y-2">
                            {run.logs.map((log) => (
                                <li key={log.id} className="flex gap-3 text-sm">
                                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${levelColors[log.level] ?? 'bg-slate-300'}`} aria-label={log.level} />
                                    <div className="min-w-0">
                                        <p className={log.level === 'error' ? 'text-red-800' : 'text-slate-800'}>
                                            {log.step_index !== null ? <span className="text-slate-500">Step {log.step_index + 1}: </span> : null}
                                            {log.message}
                                        </p>
                                        <p className="text-xs text-slate-500">
                                            {when(log.created_at)} · {humanize(log.event.replace('.', ' '))}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>
            </div>

            {run.messages.length ? (
                <Card variant="outlined" className="mt-6">
                    <CardContent>
                        <h2 className="mb-3 font-semibold text-slate-900">Messages</h2>
                        <ul className="divide-y divide-slate-100">
                            {run.messages.map((message) => (
                                <li key={message.id} className="py-3 first:pt-0 last:pb-0">
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-medium text-slate-900">{message.channel_label}</span>
                                        <span className="text-slate-600">to {message.recipient_name ? `${message.recipient_name} · ` : ''}{message.recipient}</span>
                                        <RunStatusChip status={message.status} label={message.status_label} />
                                        {message.simulated ? <Chip size="small" label="Simulated" variant="outlined" color="info" /> : null}
                                        <span className="flex-1" />
                                        {message.can_retry && canUpdate ? (
                                            <Button size="small" disabled={processing} onClick={() => router.post(`/automations/messages/${message.id}/retry`, {}, options)}>
                                                Send again
                                            </Button>
                                        ) : null}
                                    </div>
                                    {message.subject ? <p className="mt-1 text-sm font-medium text-slate-800">{message.subject}</p> : null}
                                    <p className="mt-1 rounded-lg bg-slate-50 px-3 py-2 text-sm whitespace-pre-line text-slate-700">{message.body}</p>
                                    {message.error ? <p className="mt-1 text-xs text-red-700">{message.error}</p> : null}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            ) : null}

            <ConfirmDialog
                open={confirmCancel}
                title="Cancel this run?"
                description="Steps that have not run yet will not run. Anything already done stays done."
                confirmLabel="Cancel run"
                destructive
                processing={processing}
                onConfirm={() => router.post(`/automations/runs/${run.id}/cancel`, {}, options)}
                onClose={() => setConfirmCancel(false)}
            />
        </AppLayout>
    );
}

import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import CallIcon from '@mui/icons-material/Call';
import ChatIcon from '@mui/icons-material/Chat';
import EditIcon from '@mui/icons-material/Edit';
import EmailIcon from '@mui/icons-material/Email';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import StageChip from '@/modules/leads/StageChip';
import Timeline from '@/modules/crm/Timeline';
import ActivityComposer from '@/modules/crm/ActivityComposer';
import LeadSuggestions from '@/modules/ai/LeadSuggestions';
import SummaryCard from '@/modules/ai/SummaryCard';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatMoney, formatRelative, isOverdue } from '@/utils/format';

function Detail({ label, children }) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
            <dd className="mt-1 text-sm text-slate-900">{children || '—'}</dd>
        </div>
    );
}

function whatsappLink(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '');

    return digits.length >= 7 ? `https://wa.me/${digits}` : null;
}

function StageBar({ lead, stages, canUpdate, onMove }) {
    return (
        <div className="flex flex-wrap gap-2" role="group" aria-label="Move to stage">
            {stages.map((stage) => {
                const current = stage.id === lead.lead_stage_id;

                return (
                    <button
                        key={stage.id}
                        type="button"
                        disabled={!canUpdate || current}
                        onClick={() => onMove(stage)}
                        className={`rounded-full border px-3 py-1 text-sm transition disabled:cursor-default ${current ? 'font-semibold text-white' : 'border-slate-200 bg-white text-slate-700 enabled:hover:border-slate-400'}`}
                        style={current ? { backgroundColor: stage.color, borderColor: stage.color } : undefined}
                        aria-current={current ? 'step' : undefined}
                    >
                        {stage.name}
                    </button>
                );
            })}
        </div>
    );
}

export default function Show({ lead, activities, stages, members, activityTypes, ai }) {
    const { timezone, currency, can, hasModule } = useTenant();
    const { errors } = usePage().props;
    const actionError = errors.lead_stage_id ?? errors.lost_reason ?? errors.assigned_tenant_user_id;
    const [pending, setPending] = useState(null);
    const [lostReason, setLostReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const isOpen = lead.stage?.outcome === 'open';
    const firstOf = (outcome) => stages.find((stage) => stage.outcome === outcome && stage.is_active);
    const overdue = isOpen && isOverdue(lead.next_followup_at);
    const whatsapp = whatsappLink(lead.phone);

    const move = (stage, extra = {}) => {
        router.patch(`/leads/${lead.id}/stage`, { lead_stage_id: stage.id, ...extra }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setPending(null);
                setLostReason('');
            },
        });
    };

    const requestMove = (stage) => {
        if (stage.outcome === 'open') {
            move(stage);
        } else {
            setPending({ type: stage.outcome, stage });
        }
    };

    const assign = (value) => {
        router.patch(`/leads/${lead.id}/assign`, { assigned_tenant_user_id: value === '' ? null : value }, { preserveScroll: true });
    };

    const destroy = () => {
        router.delete(`/leads/${lead.id}`, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AppLayout title={lead.name}>
            <Button component={Link} href="/leads" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                All leads
            </Button>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">{lead.name}</h1>
                        <StageChip stage={lead.stage} size="medium" />
                    </div>
                    <p className="mt-1 text-sm text-slate-600">
                        {lead.interest ? `Interested in ${lead.interest}` : 'No interest recorded'}
                        {lead.source ? ` · via ${lead.source.name}` : ''}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {lead.phone ? (
                        <Button href={`tel:${lead.phone}`} startIcon={<CallIcon />} variant="outlined" size="small">
                            Call
                        </Button>
                    ) : null}
                    {whatsapp && hasModule('messaging') && can('conversations.view') ? (
                        <Button onClick={() => router.post(`/leads/${lead.id}/chat`)} startIcon={<ChatIcon />} variant="outlined" size="small">
                            Chat
                        </Button>
                    ) : whatsapp ? (
                        <Button href={whatsapp} target="_blank" rel="noreferrer" startIcon={<ChatIcon />} variant="outlined" size="small">
                            WhatsApp
                        </Button>
                    ) : null}
                    {lead.email ? (
                        <Button href={`mailto:${lead.email}`} startIcon={<EmailIcon />} variant="outlined" size="small">
                            Email
                        </Button>
                    ) : null}
                    {can('leads.update') ? (
                        <Button component={Link} href={`/leads/${lead.id}/edit`} startIcon={<EditIcon />} size="small">
                            Edit
                        </Button>
                    ) : null}
                    {can('leads.delete') ? (
                        <Button color="error" size="small" onClick={() => setPending({ type: 'delete' })}>
                            Delete
                        </Button>
                    ) : null}
                </div>
            </div>

            {actionError ? (
                <Alert severity="error" className="mb-4">
                    {actionError}
                </Alert>
            ) : null}
            {lead.converted_at && lead.customer ? (
                <Alert severity="success" className="mb-4">
                    Converted {formatRelative(lead.converted_at)} —{' '}
                    {hasModule('customers') && can('customers.view') && !lead.customer.deleted ? (
                        <Link href={`/customers/${lead.customer.id}`} className="font-medium underline">
                            view {lead.customer.name}
                        </Link>
                    ) : (
                        <span className="font-medium">{lead.customer.name}</span>
                    )}
                </Alert>
            ) : null}
            {lead.lost_at ? (
                <Alert severity="info" className="mb-4">
                    Marked lost {formatRelative(lead.lost_at)}
                    {lead.lost_reason ? ` — ${lead.lost_reason}` : ''}
                </Alert>
            ) : null}
            {ai ? (
                <div className="mb-4">
                    <LeadSuggestions key={ai.suggestions?.id ?? 'none'} leadId={lead.id} ai={ai} />
                </div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {ai ? <SummaryCard url={`/ai/leads/${lead.id}/summary`} initial={ai.summary} /> : null}

                    <Card variant="outlined">
                        <CardContent>
                            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-semibold text-slate-900">Pipeline</h2>
                                {can('leads.update') ? (
                                    <div className="flex gap-2">
                                        {isOpen && firstOf('won') ? (
                                            <Button size="small" variant="contained" color="success" disabled={processing} onClick={() => requestMove(firstOf('won'))}>
                                                Convert to customer
                                            </Button>
                                        ) : null}
                                        {isOpen && firstOf('lost') ? (
                                            <Button size="small" color="inherit" disabled={processing} onClick={() => requestMove(firstOf('lost'))}>
                                                Mark lost
                                            </Button>
                                        ) : null}
                                        {!isOpen && firstOf('open') ? (
                                            <Button size="small" variant="outlined" disabled={processing} onClick={() => move(firstOf('open'))}>
                                                Reactivate
                                            </Button>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                            <StageBar lead={lead} stages={stages} canUpdate={can('leads.update') && !processing} onMove={requestMove} />
                        </CardContent>
                    </Card>

                    {can('leads.update') ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="mb-3 font-semibold text-slate-900">Log activity</h2>
                                <ActivityComposer
                                    url={`/leads/${lead.id}/activities`}
                                    types={activityTypes}
                                    timezone={timezone}
                                    followUp={isOpen ? lead.next_followup_at : undefined}
                                />
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="mb-4 font-semibold text-slate-900">Timeline</h2>
                            <Timeline activities={activities} timezone={timezone} />
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Details</h2>
                            <dl className="mt-4 grid gap-4">
                                <Detail label="Next follow-up">
                                    {lead.next_followup_at ? (
                                        <span className={overdue ? 'font-medium text-red-600' : undefined}>
                                            {formatDateTime(lead.next_followup_at, timezone)} ({formatRelative(lead.next_followup_at)})
                                        </span>
                                    ) : null}
                                </Detail>
                                <Detail label="Phone">{lead.phone}</Detail>
                                <Detail label="Email">{lead.email}</Detail>
                                <Detail label="Estimated value">{lead.estimated_value ? formatMoney(lead.estimated_value, currency) : null}</Detail>
                                <Detail label="Last contacted">{lead.last_contacted_at ? formatRelative(lead.last_contacted_at) : null}</Detail>
                                <Detail label="Added">{formatDateTime(lead.created_at, timezone)}</Detail>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Assigned to</h2>
                            {can('leads.assign') ? (
                                <TextField
                                    select
                                    fullWidth
                                    size="small"
                                    className="mt-3"
                                    value={lead.assigned_tenant_user_id ?? ''}
                                    onChange={(event) => assign(event.target.value)}
                                    slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Assigned to' } }}
                                >
                                    <MenuItem value="">
                                        <em>Unassigned</em>
                                    </MenuItem>
                                    {members.map((member) => (
                                        <MenuItem key={member.id} value={member.id}>
                                            {member.name}
                                        </MenuItem>
                                    ))}
                                </TextField>
                            ) : (
                                <p className="mt-2 text-sm text-slate-700">{lead.assignee?.name ?? 'Unassigned'}</p>
                            )}
                        </CardContent>
                    </Card>

                    {lead.customer && !lead.converted_at ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="font-semibold text-slate-900">Existing customer</h2>
                                <p className="mt-2 text-sm text-slate-600">
                                    This lead matches{' '}
                                    {hasModule('customers') && can('customers.view') && !lead.customer.deleted ? (
                                        <Link href={`/customers/${lead.customer.id}`} className="font-medium text-brand-700 hover:underline">
                                            {lead.customer.name}
                                        </Link>
                                    ) : (
                                        <span className="font-medium">{lead.customer.name}</span>
                                    )}
                                    .
                                </p>
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </div>

            <ConfirmDialog
                open={pending?.type === 'won'}
                title={`Convert ${lead.name}?`}
                description={
                    lead.customer
                        ? `The lead moves to "${pending?.stage?.name}" and stays linked to ${lead.customer.name}.`
                        : `The lead moves to "${pending?.stage?.name}". An existing customer with the same phone or email is reused; otherwise a new customer is created.`
                }
                confirmLabel="Convert"
                processing={processing}
                onConfirm={() => move(pending.stage)}
                onClose={() => setPending(null)}
            />
            <ConfirmDialog
                open={pending?.type === 'lost'}
                title="Mark this lead as lost?"
                confirmLabel="Mark lost"
                processing={processing}
                onConfirm={() => move(pending.stage, { lost_reason: lostReason || null })}
                onClose={() => setPending(null)}
            >
                <TextField
                    fullWidth
                    autoFocus
                    margin="dense"
                    label="Reason (optional)"
                    value={lostReason}
                    onChange={(event) => setLostReason(event.target.value)}
                    slotProps={{ htmlInput: { maxLength: 255 } }}
                />
            </ConfirmDialog>
            <ConfirmDialog
                open={pending?.type === 'delete'}
                title={`Delete ${lead.name}?`}
                description="The lead disappears from lists. Its history stays on the linked customer, if any."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={destroy}
                onClose={() => setPending(null)}
            />
        </AppLayout>
    );
}

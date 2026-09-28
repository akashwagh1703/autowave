import { Link, router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditIcon from '@mui/icons-material/Edit';
import EventIcon from '@mui/icons-material/Event';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import AppointmentStatusChip from '@/modules/booking/AppointmentStatusChip';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatMoney } from '@/utils/format';
import { WEEKDAYS, formatDuration, formatTime } from '@/utils/booking';

function TimeOffForm({ resourceId }) {
    const form = useForm({ starts_at: '', ends_at: '', reason: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/resources/${resourceId}/time-off`, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="mt-3 space-y-3 rounded-lg border border-slate-200 p-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <TextField
                    type="datetime-local"
                    size="small"
                    label="From"
                    value={form.data.starts_at}
                    onChange={(event) => form.setData('starts_at', event.target.value)}
                    error={Boolean(form.errors.starts_at)}
                    helperText={form.errors.starts_at}
                    slotProps={{ inputLabel: { shrink: true } }}
                />
                <TextField
                    type="datetime-local"
                    size="small"
                    label="Until"
                    value={form.data.ends_at}
                    onChange={(event) => form.setData('ends_at', event.target.value)}
                    error={Boolean(form.errors.ends_at)}
                    helperText={form.errors.ends_at}
                    slotProps={{ inputLabel: { shrink: true } }}
                />
            </div>
            <TextField
                size="small"
                fullWidth
                label="Reason (optional)"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                error={Boolean(form.errors.reason)}
                helperText={form.errors.reason}
                slotProps={{ htmlInput: { maxLength: 150 } }}
            />
            <div className="flex justify-end">
                <Button type="submit" variant="outlined" size="small" startIcon={<AddIcon />} disabled={form.processing || !form.data.starts_at || !form.data.ends_at}>
                    Add time off
                </Button>
            </div>
        </form>
    );
}

export default function Show({ resource, services, upcoming, timeOff, servicesEnabled }) {
    const { timezone, currency, can, resourceLabel } = useTenant();
    const { errors } = usePage().props;
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const canManage = can('resources.manage');
    const now = Date.now();

    const destroy = () =>
        router.delete(`/resources/${resource.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setConfirmDelete(false);
            },
        });

    return (
        <AppLayout title={resource.name}>
            <Button component={Link} href="/resources" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                {resourceLabel.plural}
            </Button>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                    <span className="flex h-12 w-12 items-center justify-center rounded-full text-lg font-semibold text-white" style={{ backgroundColor: resource.color }}>
                        {resource.name.charAt(0).toUpperCase()}
                    </span>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">{resource.name}</h1>
                        <p className="text-sm text-slate-600">
                            {[resource.description, resource.member ? `Linked to ${resource.member.name}` : null].filter(Boolean).join(' · ') || resourceLabel.singular}
                            {!resource.is_active ? <Chip size="small" label="Paused" className="ml-2" /> : null}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button component={Link} href={`/appointments?resource=${resource.id}`} variant="outlined" size="small" startIcon={<EventIcon />}>
                        Calendar
                    </Button>
                    {can('appointments.create') && resource.is_active ? (
                        <Button component={Link} href={`/appointments/create?resource=${resource.id}`} variant="contained" size="small" startIcon={<AddIcon />}>
                            Book
                        </Button>
                    ) : null}
                    {canManage ? (
                        <>
                            <Button component={Link} href={`/resources/${resource.id}/edit`} size="small" startIcon={<EditIcon />}>
                                Edit
                            </Button>
                            <Button color="error" size="small" onClick={() => setConfirmDelete(true)}>
                                Delete
                            </Button>
                        </>
                    ) : null}
                </div>
            </div>

            {errors.resource ? (
                <Alert severity="error" className="mb-4">
                    {errors.resource}
                </Alert>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Upcoming appointments</h2>
                            {upcoming.length === 0 ? (
                                <p className="mt-2 text-sm text-slate-600">Nothing booked.</p>
                            ) : (
                                <ul className="mt-3 divide-y divide-slate-100">
                                    {upcoming.map((appointment) => (
                                        <li key={appointment.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                            <div>
                                                <Link href={`/appointments/${appointment.id}`} className="text-sm font-medium text-slate-900 hover:text-brand-700">
                                                    {formatDateTime(appointment.starts_at, timezone, { weekday: 'short', day: 'numeric', month: 'short' })} ·{' '}
                                                    {formatTime(appointment.starts_at, timezone)}–{formatTime(appointment.ends_at, timezone)}
                                                </Link>
                                                <p className="text-xs text-slate-500">
                                                    {appointment.customer?.name}
                                                    {appointment.service ? ` · ${appointment.service.name}` : ''}
                                                </p>
                                            </div>
                                            <AppointmentStatusChip appointment={appointment} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Time off</h2>
                            <p className="text-sm text-slate-600">Leave, maintenance or private events. Blocks booking for that period.</p>
                            {timeOff.length === 0 ? (
                                <p className="mt-2 text-sm text-slate-500">No time off planned.</p>
                            ) : (
                                <ul className="mt-3 divide-y divide-slate-100">
                                    {timeOff.map((period) => {
                                        const past = new Date(period.ends_at).getTime() < now;

                                        return (
                                            <li key={period.id} className={`flex items-center justify-between gap-2 py-2 ${past ? 'text-slate-400' : ''}`}>
                                                <div>
                                                    <p className="text-sm">
                                                        {formatDateTime(period.starts_at, timezone)} → {formatDateTime(period.ends_at, timezone)}
                                                    </p>
                                                    {period.reason ? <p className="text-xs text-slate-500">{period.reason}</p> : null}
                                                </div>
                                                {canManage ? (
                                                    <IconButton
                                                        size="small"
                                                        aria-label="Remove time off"
                                                        onClick={() => router.delete(`/resources/${resource.id}/time-off/${period.id}`, { preserveScroll: true })}
                                                    >
                                                        <DeleteOutlineIcon fontSize="small" />
                                                    </IconButton>
                                                ) : null}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                            {canManage ? <TimeOffForm resourceId={resource.id} /> : null}
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Working hours</h2>
                            <ul className="mt-3 space-y-1 text-sm">
                                {WEEKDAYS.map((day) => {
                                    const rows = (resource.working_hours ?? []).filter((row) => row.weekday === day.value);

                                    return (
                                        <li key={day.value} className="flex justify-between gap-2">
                                            <span className="text-slate-600">{day.label}</span>
                                            <span className={rows.length ? 'text-slate-900' : 'text-slate-400'}>
                                                {rows.length ? rows.map((row) => `${row.starts_at}–${row.ends_at}`).join(', ') : 'Closed'}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                            <p className="mt-2 text-xs text-slate-500">Times in {timezone}.</p>
                        </CardContent>
                    </Card>

                    {servicesEnabled ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="font-semibold text-slate-900">Services ({services.length})</h2>
                                {services.length === 0 ? (
                                    <p className="mt-2 text-sm text-slate-600">No services assigned yet.</p>
                                ) : (
                                    <ul className="mt-3 divide-y divide-slate-100">
                                        {services.map((service) => (
                                            <li key={service.id} className="flex justify-between gap-2 py-1.5 text-sm">
                                                <span className={service.is_active ? 'text-slate-900' : 'text-slate-400'}>{service.name}</span>
                                                <span className="text-slate-500">
                                                    {formatDuration(service.duration_minutes)} · {formatMoney(service.price, currency)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${resource.name}?`}
                description="Past appointments keep showing this name. You must move or cancel upcoming appointments first."
                confirmLabel="Delete"
                destructive
                processing={deleting}
                onConfirm={destroy}
                onClose={() => setConfirmDelete(false)}
            />
        </AppLayout>
    );
}

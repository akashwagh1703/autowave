import { Link, router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import CallIcon from '@mui/icons-material/Call';
import ChatIcon from '@mui/icons-material/Chat';
import EventRepeatIcon from '@mui/icons-material/EventRepeat';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import AppointmentStatusChip from '@/modules/booking/AppointmentStatusChip';
import SlotPicker from '@/modules/booking/SlotPicker';
import Timeline from '@/modules/crm/Timeline';
import PaymentDialog from '@/modules/payments/PaymentDialog';
import PaymentsList from '@/modules/payments/PaymentsList';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatMoney, humanize } from '@/utils/format';
import { formatDay, formatDuration, formatTime, localDate } from '@/utils/booking';

function Detail({ label, children }) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
            <dd className="mt-1 text-sm whitespace-pre-line text-slate-900">{children || '—'}</dd>
        </div>
    );
}

function RescheduleDialog({ appointment, resources, open, onClose, today, timezone }) {
    const { resourceLabel } = useTenant();
    const form = useForm({
        booking_resource_id: appointment.booking_resource_id,
        date: localDate(appointment.starts_at, timezone) < today ? today : localDate(appointment.starts_at, timezone),
        time: '',
        duration_minutes: appointment.duration_minutes,
        allow_outside_hours: false,
    });

    const submit = () => {
        form.transform(({ date, time, ...data }) => ({ ...data, starts_at: `${date}T${time}` }));
        form.patch(`/appointments/${appointment.id}/reschedule`, { preserveScroll: true, onSuccess: onClose });
    };

    const options = appointment.service_id ? resources.filter((resource) => resource.service_ids?.includes(appointment.service_id) || resource.id === appointment.booking_resource_id) : resources;

    return (
        <Dialog open={open} onClose={form.processing ? undefined : onClose} maxWidth="sm" fullWidth>
            <DialogTitle>Reschedule</DialogTitle>
            <DialogContent>
                <p className="mb-4 text-sm text-slate-600">
                    Currently {formatDay(localDate(appointment.starts_at, timezone))} at {formatTime(appointment.starts_at, timezone)} with {appointment.resource?.name}.
                </p>
                <div className="mb-4 grid gap-3 sm:grid-cols-2">
                    <TextField
                        select
                        size="small"
                        label={resourceLabel.singular}
                        value={form.data.booking_resource_id}
                        onChange={(event) => form.setData((data) => ({ ...data, booking_resource_id: Number(event.target.value), time: '' }))}
                        error={Boolean(form.errors.booking_resource_id)}
                        helperText={form.errors.booking_resource_id}
                    >
                        {options.map((resource) => (
                            <MenuItem key={resource.id} value={resource.id}>
                                {resource.name}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField
                        size="small"
                        type="number"
                        label="Duration"
                        value={form.data.duration_minutes}
                        onChange={(event) => form.setData((data) => ({ ...data, duration_minutes: event.target.value, time: '' }))}
                        error={Boolean(form.errors.duration_minutes)}
                        helperText={form.errors.duration_minutes}
                        slotProps={{ htmlInput: { min: 5, max: 720, step: 5 }, input: { endAdornment: <InputAdornment position="end">min</InputAdornment> } }}
                    />
                </div>
                <SlotPicker
                    resourceId={form.data.booking_resource_id}
                    duration={Number(form.data.duration_minutes) || null}
                    ignoreId={appointment.id}
                    date={form.data.date}
                    time={form.data.time}
                    today={today}
                    onDateChange={(date) => form.setData((data) => ({ ...data, date, time: '' }))}
                    onTimeChange={(time) => form.setData('time', time)}
                    error={form.errors.starts_at}
                />
                <FormControlLabel
                    control={<Checkbox checked={form.data.allow_outside_hours} onChange={(event) => form.setData('allow_outside_hours', event.target.checked)} />}
                    label={<span className="text-sm">Allow outside working hours</span>}
                />
            </DialogContent>
            <DialogActions>
                <Button onClick={onClose} color="inherit" disabled={form.processing}>
                    Cancel
                </Button>
                <Button variant="contained" onClick={submit} disabled={form.processing || !form.data.time}>
                    Move appointment
                </Button>
            </DialogActions>
        </Dialog>
    );
}

function DetailsForm({ appointment }) {
    const { currency } = useTenant();
    const form = useForm({ price: appointment.price ?? '', notes: appointment.notes ?? '' });

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, price: data.price === '' ? null : data.price }));
        form.put(`/appointments/${appointment.id}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <TextField
                size="small"
                type="number"
                label="Price"
                fullWidth
                value={form.data.price}
                onChange={(event) => form.setData('price', event.target.value)}
                error={Boolean(form.errors.price)}
                helperText={form.errors.price}
                slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
            />
            <TextField
                size="small"
                label="Notes"
                multiline
                minRows={3}
                fullWidth
                value={form.data.notes}
                onChange={(event) => form.setData('notes', event.target.value)}
                error={Boolean(form.errors.notes)}
                helperText={form.errors.notes}
                slotProps={{ htmlInput: { maxLength: 2000 } }}
            />
            <div className="flex justify-end">
                <Button type="submit" size="small" variant="outlined" disabled={form.processing || !form.isDirty}>
                    Save details
                </Button>
            </div>
        </form>
    );
}

export default function Show({ appointment, transitions, activities, resources, paymentMethods = [] }) {
    const { timezone, currency, can, resourceLabel } = useTenant();
    const { errors } = usePage().props;
    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');
    const [rescheduling, setRescheduling] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [paying, setPaying] = useState(false);
    const [removing, setRemoving] = useState(null);
    const canPay = can('appointments.update') && appointment.status !== 'cancelled' && appointment.balance !== null && Number(appointment.balance) > 0;

    const removePayment = () =>
        router.delete(`/appointments/${appointment.id}/payments/${removing.id}`, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });
    const today = localDate(new Date().toISOString(), timezone);
    const date = localDate(appointment.starts_at, timezone);
    const digits = String(appointment.customer?.phone ?? '').replace(/\D/g, '');

    const setStatus = (status, extra = {}) =>
        router.patch(`/appointments/${appointment.id}/status`, { status, ...extra }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setCancelling(false);
            },
        });

    const actionLabels = { confirmed: 'Confirm', completed: 'Mark completed', no_show: 'Mark no-show' };

    return (
        <AppLayout title={`Appointment · ${appointment.customer?.name ?? ''}`}>
            <Button component={Link} href={`/appointments?date=${date}`} startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                {formatDay(date)}
            </Button>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">{appointment.customer?.name}</h1>
                        <AppointmentStatusChip appointment={appointment} size="medium" />
                    </div>
                    <p className="mt-1 text-sm text-slate-600">
                        {formatDay(date)} · {formatTime(appointment.starts_at, timezone)}–{formatTime(appointment.ends_at, timezone)} · {appointment.resource?.name}
                        {appointment.service ? ` · ${appointment.service.name}` : ''}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {can('appointments.update')
                        ? transitions
                              .filter((transition) => transition.value !== 'cancelled')
                              .map((transition) => (
                                  <Tooltip key={transition.value} title={transition.available ? '' : 'Available once the appointment has started'}>
                                      <span>
                                          <Button
                                              variant={transition.value === 'confirmed' || transition.value === 'completed' ? 'contained' : 'outlined'}
                                              color={transition.value === 'no_show' ? 'warning' : 'primary'}
                                              size="small"
                                              disabled={!transition.available || processing}
                                              onClick={() => setStatus(transition.value)}
                                          >
                                              {actionLabels[transition.value] ?? transition.label}
                                          </Button>
                                      </span>
                                  </Tooltip>
                              ))
                        : null}
                    {appointment.is_active && can('appointments.update') ? (
                        <Button size="small" variant="outlined" startIcon={<EventRepeatIcon />} onClick={() => setRescheduling(true)} disabled={processing}>
                            Reschedule
                        </Button>
                    ) : null}
                    {transitions.some((transition) => transition.value === 'cancelled') && can('appointments.cancel') ? (
                        <Button size="small" color="error" onClick={() => setCancelling(true)} disabled={processing}>
                            Cancel appointment
                        </Button>
                    ) : null}
                </div>
            </div>

            {errors.status ? (
                <Alert severity="error" className="mb-4">
                    {errors.status}
                </Alert>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Details</h2>
                            <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <Detail label="When">{formatDateTime(appointment.starts_at, timezone)}</Detail>
                                <Detail label="Duration">{formatDuration(appointment.duration_minutes)}</Detail>
                                <Detail label={resourceLabel.singular}>
                                    {appointment.resource ? (
                                        appointment.resource.deleted ? (
                                            `${appointment.resource.name} (deleted)`
                                        ) : (
                                            <Link href={`/resources/${appointment.resource.id}`} className="text-brand-700 hover:underline">
                                                {appointment.resource.name}
                                            </Link>
                                        )
                                    ) : null}
                                </Detail>
                                <Detail label="Service">{appointment.service ? `${appointment.service.name}${appointment.service.deleted ? ' (deleted)' : ''}` : null}</Detail>
                                <Detail label="Price">{formatMoney(appointment.price, currency)}</Detail>
                                <Detail label="Source">{humanize(appointment.source)}</Detail>
                                <Detail label="Booked">
                                    {formatDateTime(appointment.created_at, timezone)}
                                    {appointment.creator ? ` by ${appointment.creator.name}` : ''}
                                </Detail>
                                {appointment.cancellation_reason ? <Detail label="Cancellation reason">{appointment.cancellation_reason}</Detail> : null}
                            </dl>
                            {appointment.notes && !can('appointments.update') ? (
                                <div className="mt-4">
                                    <Detail label="Notes">{appointment.notes}</Detail>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="mb-4 font-semibold text-slate-900">History</h2>
                            <Timeline activities={activities} timezone={timezone} />
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Customer</h2>
                            <p className="mt-2 text-sm text-slate-900">
                                {appointment.customer && can('customers.view') && !appointment.customer.deleted ? (
                                    <Link href={`/customers/${appointment.customer.id}`} className="font-medium text-brand-700 hover:underline">
                                        {appointment.customer.name}
                                    </Link>
                                ) : (
                                    appointment.customer?.name
                                )}
                            </p>
                            <p className="text-sm text-slate-600">{appointment.customer?.phone ?? 'No phone number'}</p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {appointment.customer?.phone ? (
                                    <Button href={`tel:${appointment.customer.phone}`} startIcon={<CallIcon />} variant="outlined" size="small">
                                        Call
                                    </Button>
                                ) : null}
                                {digits.length >= 7 ? (
                                    <Button href={`https://wa.me/${digits}`} target="_blank" rel="noreferrer" startIcon={<ChatIcon />} variant="outlined" size="small">
                                        WhatsApp
                                    </Button>
                                ) : null}
                            </div>
                        </CardContent>
                    </Card>

                    {can('appointments.update') ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="mb-3 font-semibold text-slate-900">Price and notes</h2>
                                <DetailsForm key={`${appointment.price}-${appointment.notes}`} appointment={appointment} />
                            </CardContent>
                        </Card>
                    ) : null}

                    {appointment.price !== null || appointment.payments?.length ? (
                        <Card variant="outlined">
                            <CardContent>
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="font-semibold text-slate-900">Payments</h2>
                                    {canPay ? (
                                        <Button size="small" variant="outlined" onClick={() => setPaying(true)} disabled={processing}>
                                            Record payment
                                        </Button>
                                    ) : null}
                                </div>
                                <p className="mt-1 text-sm text-slate-600">
                                    Paid {formatMoney(appointment.amount_paid, currency)}
                                    {appointment.balance !== null ? ` of ${formatMoney(appointment.price, currency)} · ${formatMoney(appointment.balance, currency)} due` : ''}
                                </p>
                                {errors.amount ? (
                                    <Alert severity="error" className="mt-2">
                                        {errors.amount}
                                    </Alert>
                                ) : null}
                                <PaymentsList payments={appointment.payments ?? []} onRemove={can('appointments.update') ? setRemoving : null} />
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </div>

            {appointment.is_active && can('appointments.update') ? (
                <RescheduleDialog
                    key={`${appointment.starts_at}-${appointment.booking_resource_id}`}
                    appointment={appointment}
                    resources={resources}
                    open={rescheduling}
                    onClose={() => setRescheduling(false)}
                    today={today}
                    timezone={timezone}
                />
            ) : null}

            {canPay ? (
                <PaymentDialog
                    key={appointment.balance}
                    action={`/appointments/${appointment.id}/payments`}
                    balance={appointment.balance}
                    methods={paymentMethods}
                    open={paying}
                    onClose={() => setPaying(false)}
                    title="Record payment (advance or full)"
                />
            ) : null}

            <ConfirmDialog
                open={removing !== null}
                title="Remove this payment?"
                description="Use this for a payment recorded by mistake. It is not a refund. The removal stays in the appointment history."
                confirmLabel="Remove payment"
                destructive
                processing={processing}
                onConfirm={removePayment}
                onClose={() => setRemoving(null)}
            />

            <ConfirmDialog
                open={cancelling}
                title="Cancel this appointment?"
                description="The time becomes free for new bookings. The appointment stays in the customer's history."
                confirmLabel="Cancel appointment"
                destructive
                processing={processing}
                onConfirm={() => setStatus('cancelled', { reason })}
                onClose={() => setCancelling(false)}
            >
                <TextField
                    fullWidth
                    size="small"
                    label="Reason (optional)"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    slotProps={{ htmlInput: { maxLength: 255 } }}
                    className="mt-3"
                />
            </ConfirmDialog>
        </AppLayout>
    );
}

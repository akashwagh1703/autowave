import { Link, router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import EditIcon from '@mui/icons-material/Edit';
import PaymentsIcon from '@mui/icons-material/PaymentsOutlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ConfirmDialog from '@/components/ConfirmDialog';
import Timeline from '@/modules/crm/Timeline';
import PaymentDialog from '@/modules/payments/PaymentDialog';
import PaymentsList from '@/modules/payments/PaymentsList';
import EnrolmentStatusChip from '@/modules/education/EnrolmentStatusChip';
import InstalmentsEditor from '@/modules/education/InstalmentsEditor';
import AttachmentsCard from '@/modules/files/AttachmentsCard';
import useTenant from '@/hooks/useTenant';
import { formatDay } from '@/utils/booking';
import { netFee } from '@/utils/education';
import { formatPrice } from '@/utils/format';

const ATTENDANCE_COLORS = { present: 'bg-emerald-500', late: 'bg-amber-400', absent: 'bg-red-500', excused: 'bg-slate-300' };

function FeePlanDialog({ enrolment, max, open, onClose }) {
    const { currency } = useTenant();
    const form = useForm({
        fee_total: enrolment.fee_total,
        discount: enrolment.discount,
        instalments: (enrolment.instalments ?? []).map((row) => ({ due_on: row.due_on, amount: row.amount })),
    });
    const net = netFee(form.data.fee_total, form.data.discount);

    const submit = (event) => {
        event.preventDefault();
        form.put(`/students/${enrolment.id}/fees`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>Edit fee plan</DialogTitle>
                <DialogContent className="space-y-4">
                    <p className="text-sm text-slate-600">
                        {formatPrice(enrolment.amount_paid, currency)} already paid. Payments are re-applied to the new instalments in date order.
                    </p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label="Fee"
                            type="number"
                            value={form.data.fee_total}
                            onChange={(event) => form.setData('fee_total', event.target.value)}
                            error={Boolean(form.errors.fee_total)}
                            helperText={form.errors.fee_total}
                            slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                            className="mt-1"
                        />
                        <TextField
                            label="Discount"
                            type="number"
                            value={form.data.discount}
                            onChange={(event) => form.setData('discount', event.target.value)}
                            error={Boolean(form.errors.discount)}
                            helperText={form.errors.discount}
                            slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                            className="mt-1"
                        />
                    </div>
                    <InstalmentsEditor rows={form.data.instalments} onChange={(rows) => form.setData('instalments', rows)} net={net} errors={form.errors} max={max} />
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        Save plan
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

function NotesCard({ enrolment, canEdit }) {
    const form = useForm({ notes: enrolment.notes ?? '' });
    const dirty = (enrolment.notes ?? '') !== form.data.notes;

    return (
        <Card variant="outlined">
            <CardContent>
                <h2 className="font-semibold text-slate-900">Notes</h2>
                {canEdit ? (
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(`/students/${enrolment.id}`, { preserveScroll: true });
                        }}
                        className="mt-2 space-y-2"
                    >
                        <TextField
                            fullWidth
                            multiline
                            minRows={3}
                            value={form.data.notes}
                            onChange={(event) => form.setData('notes', event.target.value)}
                            error={Boolean(form.errors.notes)}
                            helperText={form.errors.notes}
                            placeholder="School, parent’s name, anything the team should know"
                            slotProps={{ htmlInput: { maxLength: 2000, 'aria-label': 'Notes' } }}
                        />
                        {dirty ? (
                            <Button type="submit" size="small" variant="outlined" disabled={form.processing}>
                                Save notes
                            </Button>
                        ) : null}
                    </form>
                ) : (
                    <p className="mt-2 text-sm whitespace-pre-line text-slate-700">{enrolment.notes || 'No notes.'}</p>
                )}
            </CardContent>
        </Card>
    );
}

export default function Show({ enrolment, attendance, activities, paymentMethods, maxInstalments, documents }) {
    const { can, currency, timezone } = useTenant();
    const { errors } = usePage().props;
    const [paying, setPaying] = useState(false);
    const [editingPlan, setEditingPlan] = useState(false);
    const [removing, setRemoving] = useState(null);
    const [changing, setChanging] = useState(null);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const name = enrolment.customer?.name ?? 'Student';
    const balance = Number(enrolment.balance);
    const active = enrolment.status === 'active';

    const finish = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setRemoving(null);
            setChanging(null);
            setReason('');
        },
    };

    const removePayment = () => router.delete(`/students/${enrolment.id}/payments/${removing.id}`, finish);
    const changeStatus = () => router.patch(`/students/${enrolment.id}/status`, { status: changing, reason }, finish);

    const statusCopy = {
        completed: { title: `Mark ${name} as completed?`, description: 'They leave the batch and stop getting fee reminders.', label: 'Mark completed' },
        dropped: { title: `Mark ${name} as dropped?`, description: 'They leave the batch and stop getting fee reminders. Payments are kept.', label: 'Mark dropped' },
        active: { title: `Re-activate ${name}?`, description: 'They take a seat in the batch again.', label: 'Re-activate' },
    };

    return (
        <AppLayout title={name}>
            <Button component={Link} href="/students" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Students
            </Button>
            <PageHeader
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        {name}
                        <EnrolmentStatusChip status={enrolment.status} label={enrolment.status_label} />
                    </span>
                }
                description={[
                    enrolment.batch?.label,
                    enrolment.batch?.schedule,
                    enrolment.enrolled_on ? `Admitted ${formatDay(enrolment.enrolled_on, { day: 'numeric', month: 'short', year: 'numeric' })}` : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    <>
                        {can('students.update') ? (
                            active ? (
                                <>
                                    <Button color="inherit" onClick={() => setChanging('dropped')}>
                                        Drop
                                    </Button>
                                    <Button color="inherit" onClick={() => setChanging('completed')}>
                                        Complete
                                    </Button>
                                </>
                            ) : (
                                <Button color="inherit" onClick={() => setChanging('active')}>
                                    Re-activate
                                </Button>
                            )
                        ) : null}
                        {can('fees.collect') && balance > 0 ? (
                            <Button variant="contained" startIcon={<PaymentsIcon />} onClick={() => setPaying(true)}>
                                Record payment
                            </Button>
                        ) : null}
                    </>
                }
            />

            {errors.status || errors.payment ? (
                <Alert severity="error" className="mb-4">
                    {errors.status ?? errors.payment}
                </Alert>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card variant="outlined">
                        <CardContent>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-semibold text-slate-900">Fees</h2>
                                {can('fees.manage') ? (
                                    <Button size="small" startIcon={<EditIcon />} onClick={() => setEditingPlan(true)}>
                                        Edit plan
                                    </Button>
                                ) : null}
                            </div>
                            <dl className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div>
                                    <dt className="text-xs text-slate-500">Fee</dt>
                                    <dd className="font-medium text-slate-900">{formatPrice(enrolment.fee_total, currency)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-slate-500">Discount</dt>
                                    <dd className="font-medium text-slate-900">{formatPrice(enrolment.discount, currency)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-slate-500">Paid</dt>
                                    <dd className="font-medium text-emerald-700">{formatPrice(enrolment.amount_paid, currency)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-slate-500">Balance</dt>
                                    <dd className={`font-semibold ${balance > 0 ? 'text-slate-900' : 'text-emerald-700'}`}>{balance > 0 ? formatPrice(enrolment.balance, currency) : 'Paid'}</dd>
                                </div>
                            </dl>

                            {enrolment.instalments?.length ? (
                                <ul className="mt-4 divide-y divide-slate-100 border-t border-slate-100">
                                    {enrolment.instalments.map((instalment) => (
                                        <li key={instalment.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                            <span className="text-slate-700">
                                                {instalment.sequence}. Due {formatDay(instalment.due_on, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <span className="text-slate-900">{formatPrice(instalment.amount, currency)}</span>
                                                {instalment.is_paid ? (
                                                    <Chip label="Paid" size="small" color="success" variant="outlined" />
                                                ) : instalment.is_overdue ? (
                                                    <Chip label={`Overdue · ${formatPrice(instalment.due, currency)}`} size="small" color="error" variant="outlined" />
                                                ) : Number(instalment.amount_paid) > 0 ? (
                                                    <Chip label={`${formatPrice(instalment.due, currency)} left`} size="small" color="warning" variant="outlined" />
                                                ) : null}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="mt-4 text-sm text-slate-500">No instalments — nothing to pay.</p>
                            )}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Payments</h2>
                            <PaymentsList payments={enrolment.payments ?? []} onRemove={can('fees.collect') ? setRemoving : null} />
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Attendance</h2>
                            {attendance.marked === 0 ? (
                                <p className="mt-2 text-sm text-slate-500">No classes marked yet.</p>
                            ) : (
                                <>
                                    <p className="mt-1 text-sm text-slate-600">
                                        {attendance.rate}% · attended {attendance.attended} of {attendance.marked} classes
                                    </p>
                                    <ul className="mt-3 flex flex-wrap gap-1.5" aria-label="Recent classes">
                                        {attendance.recent.map((record) => (
                                            <li
                                                key={record.held_on}
                                                title={`${formatDay(record.held_on, { weekday: 'short', day: 'numeric', month: 'short' })} · ${record.status_label}${record.topic ? ` · ${record.topic}` : ''}`}
                                                className={`h-4 w-4 rounded ${ATTENDANCE_COLORS[record.status] ?? 'bg-slate-200'}`}
                                            >
                                                <span className="sr-only">
                                                    {record.held_on}: {record.status_label}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="mt-2 flex flex-wrap gap-3 text-xs text-slate-500">
                                        {Object.entries(ATTENDANCE_COLORS).map(([status, color]) => (
                                            <span key={status} className="flex items-center gap-1">
                                                <span className={`h-2.5 w-2.5 rounded ${color}`} />
                                                {status.charAt(0).toUpperCase() + status.slice(1)}
                                            </span>
                                        ))}
                                    </p>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="mb-4 font-semibold text-slate-900">Activity</h2>
                            <Timeline activities={activities} timezone={timezone} showLead />
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card variant="outlined">
                        <CardContent className="space-y-2 text-sm">
                            <h2 className="font-semibold text-slate-900">Student</h2>
                            <p className="text-slate-700">{enrolment.customer?.phone ?? 'No phone'}</p>
                            {enrolment.customer?.email ? <p className="text-slate-700">{enrolment.customer.email}</p> : null}
                            {enrolment.customer && !enrolment.customer.deleted && can('customers.view') ? (
                                <Link href={`/customers/${enrolment.customer.id}`} className="block text-brand-700 hover:underline">
                                    Customer profile
                                </Link>
                            ) : null}
                            {enrolment.lead_id && can('leads.view') ? (
                                <Link href={`/leads/${enrolment.lead_id}`} className="block text-brand-700 hover:underline">
                                    Original enquiry
                                </Link>
                            ) : null}
                        </CardContent>
                    </Card>

                    {enrolment.batch ? (
                        <Card variant="outlined">
                            <CardContent className="space-y-1 text-sm">
                                <h2 className="font-semibold text-slate-900">Batch</h2>
                                {enrolment.batch.deleted ? (
                                    <p className="text-slate-700">{enrolment.batch.label} (deleted)</p>
                                ) : (
                                    <Link href={`/batches/${enrolment.batch.id}`} className="block text-brand-700 hover:underline">
                                        {enrolment.batch.label}
                                    </Link>
                                )}
                                {enrolment.batch.schedule ? <p className="text-slate-600">{enrolment.batch.schedule}</p> : null}
                                {enrolment.batch.teacher ? <p className="text-slate-600">Teacher: {enrolment.batch.teacher.name}</p> : null}
                                {enrolment.batch.room ? <p className="text-slate-600">Room: {enrolment.batch.room}</p> : null}
                            </CardContent>
                        </Card>
                    ) : null}

                    <NotesCard enrolment={enrolment} canEdit={can('students.update')} />

                    {documents ? <AttachmentsCard documents={documents} timezone={timezone} /> : null}

                    {enrolment.creator ? <p className="text-xs text-slate-500">Admitted by {enrolment.creator.name}</p> : null}
                </div>
            </div>

            {paying ? <PaymentDialog action={`/students/${enrolment.id}/payments`} balance={enrolment.balance} methods={paymentMethods} open onClose={() => setPaying(false)} title="Record fee payment" /> : null}
            {editingPlan ? <FeePlanDialog enrolment={enrolment} max={maxInstalments} open onClose={() => setEditingPlan(false)} /> : null}

            <ConfirmDialog
                open={removing !== null}
                title="Remove this payment?"
                description={removing ? `${formatPrice(removing.amount, currency)} (${removing.method_label}) will be taken off the student’s paid amount. Use this for mistakes only.` : ''}
                confirmLabel="Remove payment"
                destructive
                processing={processing}
                onConfirm={removePayment}
                onClose={() => setRemoving(null)}
            />

            <ConfirmDialog
                open={changing !== null}
                title={statusCopy[changing]?.title ?? ''}
                description={statusCopy[changing]?.description}
                confirmLabel={statusCopy[changing]?.label}
                destructive={changing === 'dropped'}
                processing={processing}
                onConfirm={changeStatus}
                onClose={() => setChanging(null)}
            >
                {changing === 'dropped' ? (
                    <TextField
                        fullWidth
                        size="small"
                        label="Reason (optional)"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        slotProps={{ htmlInput: { maxLength: 255 } }}
                        className="mt-3"
                    />
                ) : null}
            </ConfirmDialog>
        </AppLayout>
    );
}

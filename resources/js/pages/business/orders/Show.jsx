import { Link, router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import CallIcon from '@mui/icons-material/Call';
import ChatIcon from '@mui/icons-material/Chat';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import Timeline from '@/modules/crm/Timeline';
import { OrderStatusChip, PaymentChip } from '@/modules/orders/OrderChips';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatPrice, toLocalInput } from '@/utils/format';

function Row({ label, value, strong = false }) {
    return (
        <div className={`flex justify-between gap-3 ${strong ? 'border-t border-slate-200 pt-2 text-base font-semibold text-slate-900' : 'text-sm text-slate-700'}`}>
            <dt>{label}</dt>
            <dd>{value}</dd>
        </div>
    );
}

function PaymentDialog({ order, methods, open, onClose }) {
    const { currency, timezone } = useTenant();
    const form = useForm({ amount: order.balance, method: methods[0]?.value ?? 'cash', reference: '', paid_at: toLocalInput(new Date().toISOString(), timezone) });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/orders/${order.id}/payments`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>Record payment</DialogTitle>
                <DialogContent className="space-y-4">
                    <p className="text-sm text-slate-600">Balance due: {formatPrice(order.balance, currency)}</p>
                    <TextField
                        label="Amount"
                        type="number"
                        required
                        fullWidth
                        autoFocus
                        value={form.data.amount}
                        onChange={(event) => form.setData('amount', event.target.value)}
                        error={Boolean(form.errors.amount)}
                        helperText={form.errors.amount}
                        slotProps={{ htmlInput: { min: 0.01, max: order.balance, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                    />
                    <TextField
                        select
                        label="Method"
                        fullWidth
                        value={form.data.method}
                        onChange={(event) => form.setData('method', event.target.value)}
                        error={Boolean(form.errors.method)}
                        helperText={form.errors.method}
                    >
                        {methods.map((method) => (
                            <MenuItem key={method.value} value={method.value}>
                                {method.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField
                        label="Reference"
                        fullWidth
                        value={form.data.reference}
                        onChange={(event) => form.setData('reference', event.target.value)}
                        error={Boolean(form.errors.reference)}
                        helperText={form.errors.reference ?? 'Optional, e.g. UPI transaction ID.'}
                        slotProps={{ htmlInput: { maxLength: 100 } }}
                    />
                    <TextField
                        label="Received"
                        type="datetime-local"
                        fullWidth
                        value={form.data.paid_at}
                        onChange={(event) => form.setData('paid_at', event.target.value)}
                        error={Boolean(form.errors.paid_at)}
                        helperText={form.errors.paid_at}
                        slotProps={{ inputLabel: { shrink: true } }}
                    />
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        Record payment
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

function NotesForm({ order }) {
    const form = useForm({ notes: order.notes ?? '' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.put(`/orders/${order.id}`, { preserveScroll: true });
            }}
            className="space-y-2"
        >
            <TextField
                size="small"
                label="Notes"
                multiline
                minRows={3}
                fullWidth
                value={form.data.notes}
                onChange={(event) => form.setData('notes', event.target.value)}
                error={Boolean(form.errors.notes)}
                helperText={form.errors.notes ?? 'Only visible to your team.'}
                slotProps={{ htmlInput: { maxLength: 2000 } }}
            />
            <div className="flex justify-end">
                <Button type="submit" size="small" variant="outlined" disabled={form.processing || !form.isDirty}>
                    Save notes
                </Button>
            </div>
        </form>
    );
}

export default function Show({ order, transitions, activities, paymentMethods }) {
    const { timezone, currency, can } = useTenant();
    const { errors } = usePage().props;
    const [processing, setProcessing] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');
    const [paying, setPaying] = useState(false);
    const [removing, setRemoving] = useState(null);
    const canUpdate = can('orders.update');
    const digits = String(order.customer?.phone ?? '').replace(/\D/g, '');
    const hasBalance = Number(order.balance) > 0;

    const setStatus = (status, extra = {}) =>
        router.patch(`/orders/${order.id}/status`, { status, ...extra }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setCancelling(false);
            },
        });

    const removePayment = () =>
        router.delete(`/orders/${order.id}/payments/${removing.id}`, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });

    const primary = transitions.find((transition) => transition.value !== 'cancelled');
    const secondary = transitions.filter((transition) => transition.value !== 'cancelled' && transition !== primary);
    const actionLabel = (transition) =>
        ({ confirmed: 'Confirm order', completed: order.fulfilment === 'delivery' ? 'Mark delivered' : 'Mark completed' })[transition.value] ?? `Mark ${transition.label.toLowerCase()}`;

    return (
        <AppLayout title={`Order ${order.reference}`}>
            <Button component={Link} href="/orders" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Orders
            </Button>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900">Order {order.reference}</h1>
                        <OrderStatusChip order={order} size="medium" />
                        <PaymentChip order={order} size="medium" />
                    </div>
                    <p className="mt-1 text-sm text-slate-600">
                        {formatDateTime(order.created_at, timezone)} · {order.fulfilment_label} · {order.source_label}
                        {order.creator ? ` · by ${order.creator.name}` : ''}
                    </p>
                </div>
                {canUpdate ? (
                    <div className="flex flex-wrap gap-2">
                        {primary ? (
                            <Button variant="contained" size="small" disabled={processing} onClick={() => setStatus(primary.value)}>
                                {actionLabel(primary)}
                            </Button>
                        ) : null}
                        {secondary.map((transition) => (
                            <Button key={transition.value} variant="outlined" size="small" disabled={processing} onClick={() => setStatus(transition.value)}>
                                {actionLabel(transition)}
                            </Button>
                        ))}
                        {hasBalance && order.status !== 'cancelled' ? (
                            <Button variant="outlined" size="small" disabled={processing} onClick={() => setPaying(true)}>
                                Record payment
                            </Button>
                        ) : null}
                        {transitions.some((transition) => transition.value === 'cancelled') ? (
                            <Button size="small" color="error" disabled={processing} onClick={() => setCancelling(true)}>
                                Cancel order
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </div>

            {errors.status || errors.amount ? (
                <Alert severity="error" className="mb-4">
                    {errors.status ?? errors.amount}
                </Alert>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Items</h2>
                            <ul className="mt-3 divide-y divide-slate-100">
                                {order.items.map((item) => (
                                    <li key={item.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                        <div className="min-w-0">
                                            <p className="text-slate-900">
                                                {item.quantity} × {item.product_name}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {formatPrice(item.unit_price, currency)} each
                                                {item.sku ? ` · ${item.sku}` : ''}
                                                {item.product_available === false ? ' · product deleted' : ''}
                                            </p>
                                        </div>
                                        <span className="font-medium text-slate-900">{formatPrice(item.line_total, currency)}</span>
                                    </li>
                                ))}
                            </ul>
                            <dl className="mt-3 space-y-1 border-t border-slate-200 pt-3">
                                <Row label="Subtotal" value={formatPrice(order.subtotal, currency)} />
                                {Number(order.discount) > 0 ? <Row label="Discount" value={`− ${formatPrice(order.discount, currency)}`} /> : null}
                                {order.fulfilment === 'delivery' ? <Row label="Delivery" value={formatPrice(order.delivery_fee, currency)} /> : null}
                                <Row label="Total" value={formatPrice(order.total, currency)} strong />
                                <Row label="Paid" value={formatPrice(order.amount_paid, currency)} />
                                {hasBalance && order.status !== 'cancelled' ? <Row label="Balance due" value={formatPrice(order.balance, currency)} strong /> : null}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Payments</h2>
                            {order.payments.length === 0 ? (
                                <p className="mt-2 text-sm text-slate-500">No payments recorded.</p>
                            ) : (
                                <ul className="mt-2 divide-y divide-slate-100">
                                    {order.payments.map((payment) => (
                                        <li key={payment.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                            <div>
                                                <p className="text-slate-900">
                                                    {formatPrice(payment.amount, currency)} · {payment.method_label}
                                                    {payment.reference ? <span className="text-slate-500"> · {payment.reference}</span> : null}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {formatDateTime(payment.paid_at, timezone)}
                                                    {payment.recorded_by ? ` · recorded by ${payment.recorded_by}` : ''}
                                                </p>
                                            </div>
                                            {canUpdate ? (
                                                <IconButton size="small" aria-label="Remove payment" onClick={() => setRemoving(payment)}>
                                                    <DeleteOutlineIcon fontSize="small" />
                                                </IconButton>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="mt-3 text-xs text-slate-500">Payments are recorded by hand. Online payments are not available yet.</p>
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
                                {order.customer && can('customers.view') && !order.customer.deleted ? (
                                    <Link href={`/customers/${order.customer.id}`} className="font-medium text-brand-700 hover:underline">
                                        {order.customer.name}
                                    </Link>
                                ) : (
                                    order.customer?.name
                                )}
                            </p>
                            <p className="text-sm text-slate-600">{order.customer?.phone ?? 'No phone number'}</p>
                            {order.customer?.email ? <p className="text-sm text-slate-600">{order.customer.email}</p> : null}
                            <div className="mt-3 flex flex-wrap gap-2">
                                {order.customer?.phone ? (
                                    <Button href={`tel:${order.customer.phone}`} startIcon={<CallIcon />} variant="outlined" size="small">
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

                    {order.fulfilment === 'delivery' ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="font-semibold text-slate-900">Deliver to</h2>
                                <p className="mt-2 text-sm whitespace-pre-line text-slate-700">{order.delivery_address}</p>
                            </CardContent>
                        </Card>
                    ) : null}

                    {order.cancellation_reason ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="font-semibold text-slate-900">Cancellation reason</h2>
                                <p className="mt-2 text-sm text-slate-700">{order.cancellation_reason}</p>
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card variant="outlined">
                        <CardContent>
                            {canUpdate ? (
                                <NotesForm key={order.notes ?? ''} order={order} />
                            ) : (
                                <>
                                    <h2 className="font-semibold text-slate-900">Notes</h2>
                                    <p className="mt-2 text-sm whitespace-pre-line text-slate-700">{order.notes || '—'}</p>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {canUpdate && hasBalance ? (
                <PaymentDialog key={order.balance} order={order} methods={paymentMethods} open={paying} onClose={() => setPaying(false)} />
            ) : null}

            <ConfirmDialog
                open={cancelling}
                title={`Cancel order ${order.reference}?`}
                description={`Stock taken by this order is put back.${Number(order.amount_paid) > 0 ? ` ${formatPrice(order.amount_paid, currency)} was paid; refund it outside AutoWave.` : ''}`}
                confirmLabel="Cancel order"
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

            <ConfirmDialog
                open={removing !== null}
                title="Remove this payment?"
                description="Use this for a payment recorded by mistake. It is not a refund. The removal stays in the order history."
                confirmLabel="Remove payment"
                destructive
                processing={processing}
                onConfirm={removePayment}
                onClose={() => setRemoving(null)}
            />
        </AppLayout>
    );
}

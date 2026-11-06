import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import InputAdornment from '@mui/material/InputAdornment';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import DownloadIcon from '@mui/icons-material/Download';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';
import { downloadDataUri } from '@/utils/download';

function MoneyField({ form, name, label, helperText, disabled }) {
    const { currency } = useTenant();

    return (
        <TextField
            label={label}
            type="number"
            fullWidth
            value={form.data[name] ?? ''}
            disabled={disabled}
            onChange={(event) => form.setData(name, event.target.value)}
            error={Boolean(form.errors[name])}
            helperText={form.errors[name] ?? helperText}
            slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
        />
    );
}

export default function Commerce({ online, confirmation, payment_qr }) {
    const { can, hasModule, tenant } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({
        enabled: online.enabled,
        auto_confirm: online.auto_confirm,
        pickup: online.pickup,
        delivery: online.delivery,
        delivery_fee: online.delivery_fee ?? '',
        free_delivery_over: online.free_delivery_over ?? '',
        min_order: online.min_order ?? '',
        delivery_note: online.delivery_note ?? '',
        whatsapp: confirmation.whatsapp,
        include_qr: confirmation.include_qr,
        qr_payload: confirmation.qr_payload,
        upi_id: confirmation.upi_id ?? '',
        upi_payee: confirmation.upi_payee ?? '',
        payment_message: confirmation.payment_message ?? '',
        message: confirmation.message ?? '',
    });
    const locked = !canUpdate || !form.data.enabled;
    const confirmationLocked = !canUpdate || !form.data.whatsapp;
    const paymentQrFilename = `${tenant?.slug || 'business'}-customer-payment-qr.png`;

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/commerce', { preserveScroll: true });
    };

    return (
        <AppLayout title="Order settings">
            <Button component={Link} href="/orders" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Orders
            </Button>
            <PageHeader
                title="Order settings"
                description={canUpdate ? 'Let customers order from your website, and choose pickup, delivery or both.' : 'You have view-only access.'}
            />

            <form onSubmit={submit} noValidate className="max-w-3xl space-y-4">
                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Online ordering</h2>
                        <FormControlLabel
                            control={<Switch checked={form.data.enabled} disabled={!canUpdate} onChange={(event) => form.setData('enabled', event.target.checked)} />}
                            label="Take orders from my website"
                        />
                        <p className="text-sm text-slate-600">
                            Visitors add products to a cart and send the order. With WhatsApp payment on, they pay via QR and send a screenshot; you confirm after you see the payment.
                            {hasModule('website') ? (
                                <>
                                    {' '}The cart appears when the Products section is switched on under{' '}
                                    <Link href="/website" className="font-medium text-brand-700 hover:underline">
                                        Website
                                    </Link>
                                    .
                                </>
                            ) : null}
                        </p>
                        <FormControlLabel
                            control={<Switch checked={form.data.auto_confirm} disabled={locked} onChange={(event) => form.setData('auto_confirm', event.target.checked)} />}
                            label="Confirm website orders automatically"
                        />
                        <p className="text-sm text-slate-600">When off, new website orders wait as Pending until someone confirms them.</p>
                        <MoneyField form={form} name="min_order" label="Minimum order" helperText="Optional. Smaller carts cannot check out." disabled={locked} />
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Pickup and delivery</h2>
                        <div className="flex flex-wrap gap-4">
                            <FormControlLabel
                                control={<Switch checked={form.data.pickup} disabled={locked} onChange={(event) => form.setData('pickup', event.target.checked)} />}
                                label="Pickup from the store"
                            />
                            <FormControlLabel
                                control={<Switch checked={form.data.delivery} disabled={locked} onChange={(event) => form.setData('delivery', event.target.checked)} />}
                                label="Local delivery"
                            />
                        </div>
                        {form.errors.pickup ? <p className="text-sm text-red-600">{form.errors.pickup}</p> : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <MoneyField form={form} name="delivery_fee" label="Delivery fee" helperText="A flat fee added to delivery orders." disabled={locked || !form.data.delivery} />
                            <MoneyField
                                form={form}
                                name="free_delivery_over"
                                label="Free delivery from"
                                helperText="Optional. Orders at or above this subtotal deliver free."
                                disabled={locked || !form.data.delivery}
                            />
                        </div>
                        <TextField
                            label="Delivery note"
                            fullWidth
                            value={form.data.delivery_note}
                            disabled={locked}
                            onChange={(event) => form.setData('delivery_note', event.target.value)}
                            error={Boolean(form.errors.delivery_note)}
                            helperText={form.errors.delivery_note ?? 'Shown at checkout, e.g. "We deliver within 5 km, usually the same day."'}
                            slotProps={{ htmlInput: { maxLength: 200 } }}
                        />
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Customer WhatsApp</h2>
                        <FormControlLabel
                            control={<Switch checked={form.data.whatsapp} disabled={!canUpdate} onChange={(event) => form.setData('whatsapp', event.target.checked)} />}
                            label="Message the customer about payment and confirmation"
                        />
                        <p className="text-sm text-slate-600">
                            WhatsApp and website orders that stay pending get a payment QR. The customer pays and replies with a screenshot — that image appears on the order so you can review it, record the payment, then confirm. Confirmation WhatsApp has no counter/delivery QR. Needs the customer phone and an open WhatsApp window (or an approved template later). Keep &ldquo;Confirm website orders automatically&rdquo; off for this flow.
                        </p>
                        <FormControlLabel
                            control={<Switch checked={form.data.include_qr} disabled={confirmationLocked} onChange={(event) => form.setData('include_qr', event.target.checked)} />}
                            label="Attach a payment QR on the payment request"
                        />
                        <FormControl fullWidth disabled={confirmationLocked || !form.data.include_qr} error={Boolean(form.errors.qr_payload)}>
                            <InputLabel id="qr-payload-label">Payment QR encodes</InputLabel>
                            <Select
                                labelId="qr-payload-label"
                                label="Payment QR encodes"
                                value={form.data.qr_payload}
                                onChange={(event) => form.setData('qr_payload', event.target.value)}
                            >
                                <MenuItem value="upi">UPI payment link (order amount filled in)</MenuItem>
                                <MenuItem value="status">Order status page link</MenuItem>
                                <MenuItem value="reference">Order reference only</MenuItem>
                            </Select>
                            {form.errors.qr_payload ? <FormHelperText>{form.errors.qr_payload}</FormHelperText> : null}
                        </FormControl>
                        {form.data.include_qr && form.data.qr_payload === 'upi' ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <TextField
                                    label="UPI ID"
                                    fullWidth
                                    value={form.data.upi_id}
                                    disabled={confirmationLocked}
                                    onChange={(event) => form.setData('upi_id', event.target.value)}
                                    error={Boolean(form.errors.upi_id)}
                                    helperText={form.errors.upi_id ?? 'e.g. shop@upi'}
                                />
                                <TextField
                                    label="Payee name"
                                    fullWidth
                                    value={form.data.upi_payee}
                                    disabled={confirmationLocked}
                                    onChange={(event) => form.setData('upi_payee', event.target.value)}
                                    error={Boolean(form.errors.upi_payee)}
                                    helperText={form.errors.upi_payee ?? 'Optional. Shown in the payment app.'}
                                />
                            </div>
                        ) : null}
                        {payment_qr ? (
                            <div className="flex flex-col items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:flex-row sm:items-center">
                                <img src={payment_qr} alt="Customer payment QR" className="h-40 w-40 rounded border border-slate-200 bg-white p-2" />
                                <div className="space-y-2 text-sm">
                                    <p className="font-medium text-slate-800">Reusable customer payment QR</p>
                                    <p className="text-slate-600">
                                        For sharing with customers only (not for counter or delivery staff). Per-order WhatsApp payment messages still use your UPI ID with the order amount filled in.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outlined"
                                        size="small"
                                        startIcon={<DownloadIcon />}
                                        onClick={() => downloadDataUri(payment_qr, paymentQrFilename)}
                                    >
                                        Download payment QR
                                    </Button>
                                </div>
                            </div>
                        ) : form.data.include_qr && form.data.qr_payload === 'upi' ? (
                            <p className="text-sm text-slate-600">Save a UPI ID to preview and download a reusable customer payment QR.</p>
                        ) : form.data.include_qr ? (
                            <p className="text-sm text-slate-600">Status and reference QRs are created per pending order with the payment request. Prefer UPI so customers can pay the order amount.</p>
                        ) : null}
                        <TextField
                            label="Payment request message"
                            fullWidth
                            multiline
                            minRows={4}
                            value={form.data.payment_message}
                            disabled={confirmationLocked}
                            onChange={(event) => form.setData('payment_message', event.target.value)}
                            error={Boolean(form.errors.payment_message)}
                            helperText={
                                form.errors.payment_message ??
                                'Sent when a website order is pending. Ask them to pay and reply with a screenshot. Placeholders: {{customer.first_name}}, {{order.number}}, {{order.items}}, {{order.total}}, {{order.fulfilment}}, {{business.name}}.'
                            }
                        />
                        <TextField
                            label="Confirmation message"
                            fullWidth
                            multiline
                            minRows={3}
                            value={form.data.message}
                            disabled={confirmationLocked}
                            onChange={(event) => form.setData('message', event.target.value)}
                            error={Boolean(form.errors.message)}
                            helperText={
                                form.errors.message ??
                                'Sent after you confirm the order (once you have the payment screenshot). No QR attached.'
                            }
                        />
                    </CardContent>
                </Card>

                {canUpdate ? (
                    <div className="flex justify-end">
                        <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                            Save settings
                        </Button>
                    </div>
                ) : null}
            </form>
        </AppLayout>
    );
}

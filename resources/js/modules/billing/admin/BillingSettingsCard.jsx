import { router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import UploadIcon from '@mui/icons-material/Upload';
import ConfirmDialog from '@/components/ConfirmDialog';
import { formatBytes, formatDateTime } from '@/utils/format';

function Section({ title, children, description }) {
    return (
        <section className="space-y-3 border-t border-slate-200 pt-4">
            <div>
                <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
                {description ? <p className="text-sm text-slate-600">{description}</p> : null}
            </div>
            {children}
        </section>
    );
}

/**
 * Super Admin → Settings → Billing: who gets locked out, how businesses pay (UPI with the QR image, bank
 * transfer, online later), seller and GST details for invoices. Gateway keys live only in the server .env.
 */
export default function BillingSettingsCard({ billing }) {
    const qrInput = useRef(null);
    const [qrVersion, setQrVersion] = useState(Date.now());
    const [qrError, setQrError] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [removingQr, setRemovingQr] = useState(false);
    const form = useForm({
        enforce: billing.enforce,
        manual_enabled: billing.manual_enabled,
        online_enabled: billing.online_enabled,
        seller: { name: billing.seller?.name ?? '', address: billing.seller?.address ?? '', email: billing.seller?.email ?? '', phone: billing.seller?.phone ?? '' },
        gst: { enabled: Boolean(billing.gst?.enabled), gstin: billing.gst?.gstin ?? '' },
        upi: { id: billing.upi?.id ?? '', payee: billing.upi?.payee ?? '' },
        bank: {
            account_name: billing.bank?.account_name ?? '',
            account_number: billing.bank?.account_number ?? '',
            ifsc: billing.bank?.ifsc ?? '',
            bank_name: billing.bank?.bank_name ?? '',
        },
        instructions: billing.instructions ?? '',
    });
    const set = (group, key, value) => form.setData(group, { ...form.data[group], [key]: value });
    const field = (group, key, label, props = {}) => (
        <TextField
            size="small"
            fullWidth
            label={label}
            value={form.data[group][key]}
            onChange={(event) => set(group, key, event.target.value)}
            error={Boolean(form.errors[`${group}.${key}`])}
            helperText={form.errors[`${group}.${key}`] ?? props.helperText}
            {...props}
        />
    );

    const save = (event) => {
        event.preventDefault();
        form.put('/settings/billing', { preserveScroll: true });
    };

    const uploadQr = (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        if (file.size > billing.qr_max_kb * 1024) {
            setQrError(`The QR image is ${formatBytes(file.size)}. The limit is ${formatBytes(billing.qr_max_kb * 1024)}.`);

            return;
        }

        setQrError(null);
        router.post('/settings/billing/qr', { qr: file }, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setUploading(true),
            onFinish: () => setUploading(false),
            onSuccess: () => setQrVersion(Date.now()),
            onError: (errors) => setQrError(errors.qr ?? 'The QR image could not be uploaded.'),
        });
    };

    const removeQr = () =>
        router.delete('/settings/billing/qr', {
            preserveScroll: true,
            onStart: () => setUploading(true),
            onFinish: () => {
                setUploading(false);
                setRemovingQr(false);
            },
        });

    return (
        <Card variant="outlined" className="mt-4">
            <CardContent>
                <form onSubmit={save} className="space-y-4">
                    <div>
                        <h2 className="font-semibold text-slate-900">Billing</h2>
                        <p className="text-sm text-slate-600">How businesses pay for AutoWave. New businesses get a free trial; nothing is charged automatically.</p>
                    </div>

                    <div className="space-y-1">
                        <FormControlLabel control={<Switch checked={form.data.enforce} onChange={(event) => form.setData('enforce', event.target.checked)} />} label="Enforce plans" />
                        <p className="text-sm text-slate-600">
                            When on, a business whose plan ended becomes read-only after the grace days, then locked (website offline) until it pays. When
                            off, everyone keeps full access and only sees reminders.
                        </p>
                    </div>

                    <Section title="Ways to pay" description="Keep at least one on. Switching between them never affects plans already paid.">
                        <FormControlLabel control={<Switch checked={form.data.manual_enabled} onChange={(event) => form.setData('manual_enabled', event.target.checked)} />} label="UPI and bank transfer (checked by you)" />
                        <FormControlLabel
                            control={<Switch checked={form.data.online_enabled} disabled={!billing.gateway.configured && !form.data.online_enabled} onChange={(event) => form.setData('online_enabled', event.target.checked)} />}
                            label={`Online payment · ${billing.gateway.name}`}
                        />
                        {billing.gateway.webhook_url ? (
                            <p className="text-xs text-slate-500">
                                Webhook URL for the gateway dashboard (events payment.captured, payment.authorized, payment.failed, order.paid):{' '}
                                <span className="font-mono break-all text-slate-700">{billing.gateway.webhook_url}</span>
                            </p>
                        ) : null}
                        {form.errors.manual_enabled ? <Alert severity="error">{form.errors.manual_enabled}</Alert> : null}
                        {form.errors.online_enabled ? <Alert severity="error">{form.errors.online_enabled}</Alert> : null}
                    </Section>

                    <Section title="UPI" description="Businesses see the UPI ID, your QR image and a QR with the exact amount filled in.">
                        <div className="grid gap-3 sm:grid-cols-2">
                            {field('upi', 'id', 'UPI ID', { placeholder: 'business@okaxis' })}
                            {field('upi', 'payee', 'Payee name', { helperText: 'As it appears in UPI apps' })}
                        </div>
                        <div className="flex flex-wrap items-center gap-4">
                            {billing.qr ? (
                                <img src={`/settings/billing/qr?v=${qrVersion}`} alt="Current UPI QR code" className="h-32 w-32 rounded border border-slate-200 bg-white object-contain p-1" />
                            ) : (
                                <div className="flex h-32 w-32 items-center justify-center rounded border border-dashed border-slate-300 text-center text-xs text-slate-500">No QR image</div>
                            )}
                            <div className="space-y-2">
                                <input ref={qrInput} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={uploadQr} aria-label="UPI QR image" />
                                <div className="flex gap-2">
                                    <Button size="small" variant="outlined" startIcon={<UploadIcon />} disabled={uploading} onClick={() => qrInput.current?.click()}>
                                        {billing.qr ? 'Replace QR' : 'Upload QR'}
                                    </Button>
                                    {billing.qr ? (
                                        <Button size="small" color="error" disabled={uploading} onClick={() => setRemovingQr(true)}>
                                            Remove
                                        </Button>
                                    ) : null}
                                </div>
                                {billing.qr ? (
                                    <p className="text-xs text-slate-500">
                                        Uploaded {formatDateTime(billing.qr.updated_at, 'Asia/Kolkata')}
                                        {billing.qr.updated_by ? ` by ${billing.qr.updated_by}` : ''}
                                    </p>
                                ) : null}
                                <p className="text-xs text-slate-500">JPG, PNG or WebP, up to {formatBytes(billing.qr_max_kb * 1024)}. Saved immediately; every admin is emailed.</p>
                                {qrError ? <p className="text-sm text-red-600">{qrError}</p> : null}
                            </div>
                        </div>
                    </Section>

                    <Section title="Bank transfer" description="Optional. Shown with copy buttons for NEFT, IMPS or RTGS.">
                        <div className="grid gap-3 sm:grid-cols-2">
                            {field('bank', 'account_name', 'Account name')}
                            {field('bank', 'account_number', 'Account number', { slotProps: { htmlInput: { inputMode: 'numeric', autoComplete: 'off' } } })}
                            {field('bank', 'ifsc', 'IFSC', { slotProps: { htmlInput: { maxLength: 11 } } })}
                            {field('bank', 'bank_name', 'Bank and branch')}
                        </div>
                        <TextField
                            size="small"
                            fullWidth
                            multiline
                            minRows={2}
                            label="Instructions (optional)"
                            value={form.data.instructions}
                            onChange={(event) => form.setData('instructions', event.target.value)}
                            error={Boolean(form.errors.instructions)}
                            helperText={form.errors.instructions ?? 'e.g. Payments are confirmed within a few working hours.'}
                            slotProps={{ htmlInput: { maxLength: 1000 } }}
                        />
                    </Section>

                    <Section title="Invoice details" description="Printed on every invoice issued from now on. Earlier invoices keep what they had.">
                        <div className="grid gap-3 sm:grid-cols-2">
                            {field('seller', 'name', 'Business name')}
                            {field('seller', 'email', 'Email')}
                            {field('seller', 'phone', 'Phone')}
                            {field('seller', 'address', 'Address', { multiline: true, minRows: 2 })}
                        </div>
                        <FormControlLabel control={<Switch checked={form.data.gst.enabled} onChange={(event) => set('gst', 'enabled', event.target.checked)} />} label={`Charge GST (${billing.gst_rate}%)`} />
                        {form.data.gst.enabled ? (
                            <>
                                {field('gst', 'gstin', 'Your GSTIN', { slotProps: { htmlInput: { maxLength: 15, style: { textTransform: 'uppercase' } } } })}
                                <p className="text-sm text-slate-600">
                                    Prices then exclude GST: CGST + SGST inside your state, IGST otherwise. Documents are titled “Tax Invoice”. Payments already
                                    reported keep the amount they were quoted.
                                </p>
                            </>
                        ) : (
                            <p className="text-sm text-slate-600">GST is off: documents are titled “Invoice” and no tax is added.</p>
                        )}
                    </Section>

                    <div className="flex justify-end">
                        <Button type="submit" variant="contained" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save billing settings'}
                        </Button>
                    </div>
                </form>
            </CardContent>

            <ConfirmDialog
                open={removingQr}
                title="Remove the UPI QR image?"
                description="Businesses will still see the UPI ID and a QR with the amount filled in."
                confirmLabel="Remove"
                destructive
                processing={uploading}
                onConfirm={removeQr}
                onClose={() => setRemovingQr(false)}
            />
        </Card>
    );
}

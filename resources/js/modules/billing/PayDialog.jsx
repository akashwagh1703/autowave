import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import UploadIcon from '@mui/icons-material/Upload';
import { getJson, errorMessage } from '@/utils/http';
import { formatBytes, formatDate } from '@/utils/format';
import { rupees, todayInIndia } from '@/utils/billing';

function CopyValue({ label, value }) {
    const [copied, setCopied] = useState(false);

    if (!value) {
        return null;
    }

    const copy = () => {
        navigator.clipboard?.writeText(value).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    return (
        <div className="flex items-center justify-between gap-2 rounded-md bg-slate-50 px-3 py-1.5">
            <div className="min-w-0">
                <p className="text-xs text-slate-500">{label}</p>
                <p className="truncate font-mono text-sm text-slate-900">{value}</p>
            </div>
            <Tooltip title={copied ? 'Copied' : 'Copy'}>
                <IconButton size="small" onClick={copy} aria-label={`Copy ${label}`}>
                    <ContentCopyIcon fontSize="small" />
                </IconButton>
            </Tooltip>
        </div>
    );
}

/**
 * Pay for a plan by UPI or bank transfer: the server works out the amount (with any credit and GST), the
 * owner pays in their own app, then reports the UTR. AutoWave checks it and activates the plan.
 */
export default function PayDialog({ open, plan, period, methods, gst, reference, proof, subscription, timezone, onClose }) {
    const manual = methods.manual;
    const input = useRef(null);
    const [quote, setQuote] = useState(null);
    const [quoteError, setQuoteError] = useState(null);
    const [tab, setTab] = useState(manual?.upi_id || manual?.has_qr ? 'upi' : 'bank');
    const form = useForm({ plan: plan?.code, period, method: 'upi', reference: '', paid_on: todayInIndia(), buyer_gstin: '', proof: null });

    const loadQuote = (gstin = form.data.buyer_gstin) => {
        if (!plan) {
            return;
        }

        setQuoteError(null);
        getJson('/settings/billing/quote', { plan: plan.code, period, buyer_gstin: gstin })
            .then(setQuote)
            .catch((error) => setQuoteError(errorMessage(error, 'The price could not be loaded. Close this and try again.')));
    };

    useEffect(() => {
        if (open && plan) {
            setQuote(null);
            form.setData({ ...form.data, plan: plan.code, period, method: tab === 'bank' ? 'bank_transfer' : 'upi' });
            form.clearErrors();
            loadQuote();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, plan?.code, period]);

    const chooseTab = (value) => {
        setTab(value);
        form.setData('method', value === 'bank' ? 'bank_transfer' : 'upi');
    };

    const chooseFile = (event) => {
        const file = event.target.files?.[0] ?? null;
        event.target.value = '';
        form.setData('proof', file);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post('/settings/billing/payments', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    const tooLarge = form.data.proof && form.data.proof.size > proof.max_kb * 1024;
    const starts = quote?.kind === 'renewal' ? quote.from : null;
    const bank = manual?.bank;

    return (
        <Dialog open={open} onClose={form.processing ? undefined : onClose} fullWidth maxWidth="sm">
            <DialogTitle>
                Pay for {plan?.name} ({period === 'yearly' ? 'yearly' : 'monthly'})
            </DialogTitle>
            <DialogContent dividers className="space-y-4">
                {quoteError ? <Alert severity="error">{quoteError}</Alert> : null}
                {!quote && !quoteError ? (
                    <div className="flex justify-center py-6">
                        <CircularProgress size={28} />
                    </div>
                ) : null}

                {quote ? (
                    <div className="rounded-lg border border-slate-200 p-3 text-sm">
                        <div className="flex justify-between">
                            <span>{quote.plan_name} plan</span>
                            <span>{rupees(quote.price)}</span>
                        </div>
                        {quote.credit > 0 ? (
                            <div className="flex justify-between text-emerald-700">
                                <span>Credit for unused days of your current plan</span>
                                <span>−{rupees(quote.credit)}</span>
                            </div>
                        ) : null}
                        {quote.tax.map((line) => (
                            <div key={line.label} className="flex justify-between text-slate-600">
                                <span>
                                    {line.label} ({line.rate}%)
                                </span>
                                <span>{rupees(line.amount)}</span>
                            </div>
                        ))}
                        <div className="mt-2 flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-900">
                            <span>Total to pay</span>
                            <span>{rupees(quote.total)}</span>
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            {starts
                                ? `Starts when your current ${subscription.is_trial ? 'trial' : 'plan'} ends on ${formatDate(starts, timezone)}, and runs until ${formatDate(quote.until, timezone)}. You keep every remaining day.`
                                : `Starts as soon as your payment is confirmed and runs until about ${formatDate(quote.until, timezone)}.`}
                        </p>
                    </div>
                ) : null}

                {!manual ? (
                    <Alert severity="info">
                        {methods.online
                            ? 'Online payment will be available here soon.'
                            : 'Payments are not open right now. Please contact AutoWave support.'}
                    </Alert>
                ) : null}

                {manual && quote ? (
                    <>
                        <Tabs value={tab} onChange={(event, value) => chooseTab(value)} variant="fullWidth">
                            {manual.upi_id || manual.has_qr ? <Tab value="upi" label="UPI" /> : null}
                            {bank ? <Tab value="bank" label="Bank transfer" /> : null}
                        </Tabs>

                        {tab === 'upi' ? (
                            <div className="space-y-3">
                                <div className="flex flex-wrap justify-center gap-4">
                                    {quote.upi_qr ? (
                                        <figure className="text-center">
                                            <img src={quote.upi_qr} alt={`UPI QR code for ${rupees(quote.total)}`} className="mx-auto h-48 w-48 rounded border border-slate-200 bg-white p-1" />
                                            <figcaption className="mt-1 text-xs text-slate-500">Amount filled in</figcaption>
                                        </figure>
                                    ) : null}
                                    {manual.has_qr ? (
                                        <figure className="text-center">
                                            <img src="/settings/billing/qr" alt="AutoWave UPI QR code" className="mx-auto h-48 w-48 rounded border border-slate-200 bg-white object-contain p-1" />
                                            <figcaption className="mt-1 text-xs text-slate-500">Enter {rupees(quote.total)} yourself</figcaption>
                                        </figure>
                                    ) : null}
                                </div>
                                <p className="text-center text-sm text-slate-600">Scan with Google Pay, PhonePe, Paytm or any UPI app.</p>
                                {quote.upi_link ? (
                                    <Button fullWidth variant="outlined" component="a" href={quote.upi_link} sx={{ display: { sm: 'none' } }}>
                                        Open UPI app to pay {rupees(quote.total)}
                                    </Button>
                                ) : null}
                                <CopyValue label="UPI ID" value={manual.upi_id} />
                                <CopyValue label="Pay to" value={manual.payee} />
                            </div>
                        ) : null}

                        {tab === 'bank' && bank ? (
                            <div className="space-y-2">
                                <CopyValue label="Account name" value={bank.account_name} />
                                <CopyValue label="Account number" value={bank.account_number} />
                                <CopyValue label="IFSC" value={bank.ifsc} />
                                <CopyValue label="Bank" value={bank.bank_name} />
                                <CopyValue label="Amount" value={(quote.total / 100).toFixed(2)} />
                            </div>
                        ) : null}

                        <CopyValue label="Write this in the payment note / remarks" value={reference} />
                        {manual.instructions ? <p className="text-sm whitespace-pre-line text-slate-600">{manual.instructions}</p> : null}

                        <form id="pay-form" onSubmit={submit} className="space-y-3 border-t border-slate-200 pt-4">
                            <p className="text-sm font-medium text-slate-900">After paying, tell us about the payment</p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <TextField
                                    select
                                    label="Paid by"
                                    size="small"
                                    value={form.data.method}
                                    onChange={(event) => form.setData('method', event.target.value)}
                                    error={Boolean(form.errors.method)}
                                    helperText={form.errors.method}
                                >
                                    {methods.owner_methods.map((method) => (
                                        <MenuItem key={method.value} value={method.value}>
                                            {method.label}
                                        </MenuItem>
                                    ))}
                                </TextField>
                                <TextField
                                    type="date"
                                    label="Paid on"
                                    size="small"
                                    value={form.data.paid_on}
                                    onChange={(event) => form.setData('paid_on', event.target.value)}
                                    error={Boolean(form.errors.paid_on)}
                                    helperText={form.errors.paid_on}
                                    slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: todayInIndia() } }}
                                />
                            </div>
                            <TextField
                                label="UTR / transaction ID"
                                size="small"
                                fullWidth
                                required
                                value={form.data.reference}
                                onChange={(event) => form.setData('reference', event.target.value)}
                                error={Boolean(form.errors.reference)}
                                helperText={form.errors.reference ?? 'The 12-digit UTR from your UPI app, or the bank transaction reference.'}
                                slotProps={{ htmlInput: { maxLength: 40, autoComplete: 'off' } }}
                            />
                            {gst ? (
                                <TextField
                                    label="Your GSTIN (optional)"
                                    size="small"
                                    fullWidth
                                    value={form.data.buyer_gstin}
                                    onChange={(event) => form.setData('buyer_gstin', event.target.value.toUpperCase())}
                                    onBlur={() => loadQuote(form.data.buyer_gstin)}
                                    error={Boolean(form.errors.buyer_gstin)}
                                    helperText={form.errors.buyer_gstin ?? 'Shown on your tax invoice so you can claim input tax credit.'}
                                    slotProps={{ htmlInput: { maxLength: 15 } }}
                                />
                            ) : null}
                            <div>
                                <input ref={input} type="file" accept={proof.accept} className="hidden" onChange={chooseFile} aria-label="Payment screenshot" />
                                <Button size="small" variant="outlined" startIcon={<UploadIcon />} onClick={() => input.current?.click()}>
                                    {form.data.proof ? 'Change screenshot' : 'Add screenshot (optional)'}
                                </Button>
                                {form.data.proof ? (
                                    <span className="ml-2 text-xs text-slate-600">
                                        {form.data.proof.name} · {formatBytes(form.data.proof.size)}
                                    </span>
                                ) : null}
                                {tooLarge ? <p className="mt-1 text-sm text-red-600">The screenshot must be {formatBytes(proof.max_kb * 1024)} or smaller.</p> : null}
                                {form.errors.proof ? <p className="mt-1 text-sm text-red-600">{form.errors.proof}</p> : null}
                                {form.errors.plan ? <p className="mt-1 text-sm text-red-600">{form.errors.plan}</p> : null}
                                {form.errors.throttle ? <p className="mt-1 text-sm text-red-600">{form.errors.throttle}</p> : null}
                            </div>
                            {form.progress ? <LinearProgress variant="determinate" value={form.progress.percentage ?? 0} /> : null}
                        </form>
                    </>
                ) : null}
            </DialogContent>
            <DialogActions>
                <Button color="inherit" onClick={onClose} disabled={form.processing}>
                    Close
                </Button>
                {manual && quote ? (
                    <Button type="submit" form="pay-form" variant="contained" disabled={form.processing || tooLarge || !form.data.reference.trim()}>
                        {form.processing ? 'Sending…' : 'I have paid'}
                    </Button>
                ) : null}
            </DialogActions>
        </Dialog>
    );
}

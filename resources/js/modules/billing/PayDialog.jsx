import { router, useForm } from '@inertiajs/react';
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
import LockIcon from '@mui/icons-material/Lock';
import UploadIcon from '@mui/icons-material/Upload';
import { getJson, postJson, errorMessage } from '@/utils/http';
import { formatBytes, formatDate } from '@/utils/format';
import { rupees, todayInIndia } from '@/utils/billing';

const CHECKOUT_SCRIPTS = { razorpay: 'https://checkout.razorpay.com/v1/checkout.js' };
const loading = {};

function loadCheckout(gateway) {
    if (gateway === 'razorpay' && window.Razorpay) {
        return Promise.resolve();
    }

    loading[gateway] ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = CHECKOUT_SCRIPTS[gateway];
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            delete loading[gateway];
            script.remove();
            reject(new Error('The payment window could not be loaded. Check your internet connection and try again.'));
        };
        document.body.appendChild(script);
    });

    return loading[gateway];
}

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
 * Pay for a plan. The server works out the amount (credit, coupon and GST). Online: the gateway's checkout
 * takes the money and the server confirms it. UPI or bank transfer: the owner pays in their own app, then
 * reports the UTR for AutoWave to check. A coupon that covers the whole price activates the plan directly.
 */
export default function PayDialog({ open, plan, period, methods, gst, reference, proof, subscription, timezone, onClose }) {
    const manual = methods.manual;
    const input = useRef(null);
    const [quote, setQuote] = useState(null);
    const [quoteError, setQuoteError] = useState(null);
    const [couponInput, setCouponInput] = useState('');
    const [coupon, setCoupon] = useState('');
    const [couponError, setCouponError] = useState(null);
    const [checkingCoupon, setCheckingCoupon] = useState(false);
    const [tab, setTab] = useState(null);
    const [online, setOnline] = useState({ busy: false, error: null, success: null });
    const [activating, setActivating] = useState(false);
    const form = useForm({ plan: plan?.code, period, method: 'upi', reference: '', paid_on: todayInIndia(), buyer_gstin: '', coupon: '', proof: null });

    const fetchQuote = (overrides = {}) =>
        getJson('/settings/billing/quote', { plan: plan.code, period, buyer_gstin: form.data.buyer_gstin, coupon, ...overrides });

    const loadQuote = (overrides = {}) => {
        if (!plan) {
            return;
        }

        setQuoteError(null);
        fetchQuote(overrides)
            .then(setQuote)
            .catch((error) => setQuoteError(errorMessage(error, 'The price could not be loaded. Close this and try again.')));
    };

    useEffect(() => {
        if (open && plan) {
            setQuote(null);
            setCoupon('');
            setCouponInput('');
            setCouponError(null);
            setOnline({ busy: false, error: null, success: null });
            form.setData({ ...form.data, plan: plan.code, period, coupon: '' });
            form.clearErrors();
            loadQuote({ coupon: '' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, plan?.code, period]);

    const free = Boolean(quote && quote.total === 0 && quote.coupon);
    const tabs = [];

    if (quote && !free) {
        if (methods.online && quote.online_available) {
            tabs.push({ value: 'online', label: 'Pay online' });
        }

        if (manual?.upi_id || manual?.has_qr) {
            tabs.push({ value: 'upi', label: 'UPI' });
        }

        if (manual?.bank) {
            tabs.push({ value: 'bank', label: 'Bank transfer' });
        }
    }

    const activeTab = tabs.some((option) => option.value === tab) ? tab : tabs[0]?.value;

    useEffect(() => {
        if (activeTab === 'upi' || activeTab === 'bank') {
            form.setData('method', activeTab === 'bank' ? 'bank_transfer' : 'upi');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeTab]);

    const applyCoupon = () => {
        const code = couponInput.trim().toUpperCase();

        if (!code) {
            return;
        }

        setCheckingCoupon(true);
        setCouponError(null);
        fetchQuote({ coupon: code })
            .then((data) => {
                setQuote(data);
                setCoupon(code);
                form.setData('coupon', code);
            })
            .catch((error) => setCouponError(errorMessage(error, 'This coupon could not be checked. Try again.')))
            .finally(() => setCheckingCoupon(false));
    };

    const removeCoupon = () => {
        setCoupon('');
        setCouponInput('');
        setCouponError(null);
        form.setData('coupon', '');
        loadQuote({ coupon: '' });
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

    const activate = () => {
        router.post('/settings/billing/activate', { plan: plan.code, period, coupon }, {
            preserveScroll: true,
            onStart: () => setActivating(true),
            onFinish: () => setActivating(false),
            onSuccess: () => onClose(),
            onError: (errors) => setCouponError(errors.coupon ?? errors.plan ?? 'The coupon could not be applied.'),
        });
    };

    const confirmOnline = (paymentId, response) => {
        setOnline({ busy: true, error: null, success: null });
        postJson(`/settings/billing/checkout/${paymentId}/confirm`, {
            order_id: response.razorpay_order_id,
            payment_id: response.razorpay_payment_id,
            signature: response.razorpay_signature,
        })
            .then((data) => {
                setOnline({ busy: false, error: null, success: data.message });
                router.reload({ preserveScroll: true });
            })
            .catch((error) => {
                setOnline({ busy: false, error: errorMessage(error, 'Your payment is still being confirmed. We will email you; there is no need to pay again.'), success: null });
                router.reload({ preserveScroll: true });
            });
    };

    const payOnline = async () => {
        setOnline({ busy: true, error: null, success: null });

        try {
            const { checkout } = await postJson('/settings/billing/checkout', { plan: plan.code, period, buyer_gstin: form.data.buyer_gstin, coupon });
            await loadCheckout(checkout.gateway);

            const razorpay = new window.Razorpay({
                key: checkout.key,
                amount: checkout.amount,
                currency: checkout.currency,
                name: checkout.name,
                description: checkout.description,
                order_id: checkout.order_id,
                prefill: checkout.prefill,
                notes: checkout.notes,
                handler: (response) => confirmOnline(checkout.payment_id, response),
                modal: { ondismiss: () => setOnline((state) => (state.success ? state : { ...state, busy: false })) },
            });
            razorpay.on('payment.failed', (response) =>
                setOnline({ busy: false, error: response?.error?.description ?? 'The payment did not go through. You can try again.', success: null }),
            );
            razorpay.open();
        } catch (error) {
            setOnline({ busy: false, error: errorMessage(error, 'Online payment could not be started. Try again, or pay by UPI or bank transfer.'), success: null });
        }
    };

    const tooLarge = form.data.proof && form.data.proof.size > proof.max_kb * 1024;
    const starts = quote?.kind === 'renewal' ? quote.from : null;
    const bank = manual?.bank;
    const busy = form.processing || online.busy || activating;

    return (
        <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="sm">
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

                {online.success ? <Alert severity="success">{online.success}</Alert> : null}

                {quote && !online.success ? (
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
                        {quote.discount > 0 ? (
                            <div className="flex justify-between text-emerald-700">
                                <span>Coupon {quote.coupon}</span>
                                <span>−{rupees(quote.discount)}</span>
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

                        <div className="mt-3 border-t border-slate-100 pt-3">
                            {coupon ? (
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm text-emerald-700">
                                        Coupon <strong className="font-mono">{coupon}</strong> applied
                                    </span>
                                    <Button size="small" color="inherit" onClick={removeCoupon} disabled={busy}>
                                        Remove
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex items-start gap-2">
                                    <TextField
                                        size="small"
                                        label="Coupon code"
                                        value={couponInput}
                                        onChange={(event) => setCouponInput(event.target.value.toUpperCase())}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                applyCoupon();
                                            }
                                        }}
                                        error={Boolean(couponError)}
                                        helperText={couponError}
                                        slotProps={{ htmlInput: { maxLength: 30, autoComplete: 'off' } }}
                                        sx={{ flex: 1 }}
                                    />
                                    <Button variant="outlined" onClick={applyCoupon} disabled={checkingCoupon || !couponInput.trim()} sx={{ mt: '2px' }}>
                                        {checkingCoupon ? 'Checking…' : 'Apply'}
                                    </Button>
                                </div>
                            )}
                            {coupon && couponError ? <p className="mt-1 text-sm text-red-600">{couponError}</p> : null}
                        </div>
                    </div>
                ) : null}

                {quote && !free && tabs.length === 0 && !online.success ? (
                    <Alert severity="info">Payments are not open right now. Please contact AutoWave support.</Alert>
                ) : null}

                {quote && free && !online.success ? (
                    <Alert severity="success">This coupon covers the whole price. Activate the plan to start it now; there is nothing to pay.</Alert>
                ) : null}

                {quote && tabs.length > 0 && !online.success ? (
                    <>
                        <Tabs value={activeTab} onChange={(event, value) => setTab(value)} variant="fullWidth">
                            {tabs.map((option) => (
                                <Tab key={option.value} value={option.value} label={option.label} />
                            ))}
                        </Tabs>

                        {activeTab === 'online' ? (
                            <div className="space-y-3">
                                <p className="text-sm text-slate-600">
                                    Pay {rupees(quote.total)} with UPI, card, net banking or a wallet in a secure window. Your plan is updated as soon as the
                                    payment goes through.
                                </p>
                                {gst ? (
                                    <TextField
                                        label="Your GSTIN (optional)"
                                        size="small"
                                        fullWidth
                                        value={form.data.buyer_gstin}
                                        onChange={(event) => form.setData('buyer_gstin', event.target.value.toUpperCase())}
                                        onBlur={() => loadQuote()}
                                        helperText="Shown on your tax invoice so you can claim input tax credit."
                                        slotProps={{ htmlInput: { maxLength: 15 } }}
                                    />
                                ) : null}
                                {online.error ? <Alert severity="error">{online.error}</Alert> : null}
                                <p className="flex items-center gap-1 text-xs text-slate-500">
                                    <LockIcon fontSize="inherit" /> Card and bank details are entered with the payment provider, never stored by AutoWave.
                                </p>
                            </div>
                        ) : null}

                        {activeTab === 'upi' ? (
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

                        {activeTab === 'bank' && bank ? (
                            <div className="space-y-2">
                                <CopyValue label="Account name" value={bank.account_name} />
                                <CopyValue label="Account number" value={bank.account_number} />
                                <CopyValue label="IFSC" value={bank.ifsc} />
                                <CopyValue label="Bank" value={bank.bank_name} />
                                <CopyValue label="Amount" value={(quote.total / 100).toFixed(2)} />
                            </div>
                        ) : null}

                        {activeTab === 'upi' || activeTab === 'bank' ? (
                            <>
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
                                            onBlur={() => loadQuote()}
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
                                        {['proof', 'plan', 'coupon', 'throttle'].map((key) =>
                                            form.errors[key] ? (
                                                <p key={key} className="mt-1 text-sm text-red-600">
                                                    {form.errors[key]}
                                                </p>
                                            ) : null,
                                        )}
                                    </div>
                                    {form.progress ? <LinearProgress variant="determinate" value={form.progress.percentage ?? 0} /> : null}
                                </form>
                            </>
                        ) : null}
                    </>
                ) : null}
            </DialogContent>
            <DialogActions>
                <Button color="inherit" onClick={onClose} disabled={busy}>
                    Close
                </Button>
                {quote && free && !online.success ? (
                    <Button variant="contained" onClick={activate} disabled={busy}>
                        {activating ? 'Activating…' : 'Activate plan'}
                    </Button>
                ) : null}
                {quote && activeTab === 'online' && !online.success ? (
                    <Button variant="contained" onClick={payOnline} disabled={busy}>
                        {online.busy ? 'Opening…' : `Pay ${rupees(quote.total)}`}
                    </Button>
                ) : null}
                {quote && (activeTab === 'upi' || activeTab === 'bank') && !online.success ? (
                    <Button type="submit" form="pay-form" variant="contained" disabled={busy || tooLarge || !form.data.reference.trim()}>
                        {form.processing ? 'Sending…' : 'I have paid'}
                    </Button>
                ) : null}
            </DialogActions>
        </Dialog>
    );
}

import { useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { rupees, todayInIndia } from '@/utils/billing';

/** A payment received another way (cash, cheque, a transfer without a request), or a free period. */
export function RecordPaymentDialog({ tenant, plans, periods, methods, onClose }) {
    const paidPlans = plans.filter((plan) => !plan.is_trial);
    const form = useForm({
        plan: tenant.subscription?.plan && !tenant.subscription.is_trial ? tenant.subscription.plan.code : (paidPlans[0]?.code ?? ''),
        period: tenant.subscription?.period ?? 'monthly',
        method: 'bank_transfer',
        amount: '',
        reference: '',
        paid_on: todayInIndia(),
        note: '',
    });
    const plan = paidPlans.find((candidate) => candidate.code === form.data.plan);
    const listPrice = plan ? (form.data.period === 'yearly' ? plan.price_yearly : plan.price_monthly) : 0;

    const submit = (event) => {
        event.preventDefault();
        form.post(`/tenants/${tenant.id}/payments`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={form.processing ? undefined : onClose} fullWidth maxWidth="sm">
            <form onSubmit={submit}>
                <DialogTitle>Record a payment for {tenant.name}</DialogTitle>
                <DialogContent dividers className="space-y-3">
                    <p className="text-sm text-slate-600">
                        Use this when the money arrived without the owner reporting it. It is approved straight away, extends the plan and issues an
                        invoice. The owner is emailed.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField select size="small" label="Plan" value={form.data.plan} onChange={(event) => form.setData('plan', event.target.value)} error={Boolean(form.errors.plan)} helperText={form.errors.plan}>
                            {paidPlans.map((option) => (
                                <MenuItem key={option.code} value={option.code}>
                                    {option.name}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField select size="small" label="Period" value={form.data.period} onChange={(event) => form.setData('period', event.target.value)}>
                            {periods.map((option) => (
                                <MenuItem key={option.value} value={option.value}>
                                    {option.label}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField select size="small" label="Paid by" value={form.data.method} onChange={(event) => form.setData('method', event.target.value)} error={Boolean(form.errors.method)} helperText={form.errors.method}>
                            {methods.map((option) => (
                                <MenuItem key={option.value} value={option.value}>
                                    {option.label}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField
                            type="date"
                            size="small"
                            label="Received on"
                            value={form.data.paid_on}
                            onChange={(event) => form.setData('paid_on', event.target.value)}
                            error={Boolean(form.errors.paid_on)}
                            helperText={form.errors.paid_on}
                            slotProps={{ inputLabel: { shrink: true } }}
                        />
                        {form.data.method !== 'complimentary' ? (
                            <TextField
                                type="number"
                                size="small"
                                label="Amount before GST (₹)"
                                value={form.data.amount}
                                onChange={(event) => form.setData('amount', event.target.value)}
                                error={Boolean(form.errors.amount)}
                                helperText={form.errors.amount ?? `Empty = the usual price (${rupees(listPrice)}, less any upgrade credit).`}
                                slotProps={{ htmlInput: { min: 0, step: '0.01' } }}
                            />
                        ) : null}
                        <TextField
                            size="small"
                            label="Reference (optional)"
                            value={form.data.reference}
                            onChange={(event) => form.setData('reference', event.target.value)}
                            error={Boolean(form.errors.reference)}
                            helperText={form.errors.reference ?? 'UTR, cheque number, receipt number'}
                            slotProps={{ htmlInput: { maxLength: 40 } }}
                        />
                    </div>
                    <TextField size="small" fullWidth label="Note (optional)" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} slotProps={{ htmlInput: { maxLength: 500 } }} />
                    {form.data.method === 'complimentary' ? <Alert severity="info">A free period: no invoice is issued.</Alert> : null}
                </DialogContent>
                <DialogActions>
                    <Button color="inherit" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing || !form.data.plan}>
                        Record payment
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

/** Corrections: set the plan and end date directly, with a reason for the audit log. */
export function AdjustSubscriptionDialog({ tenant, plans, periods, onClose }) {
    const endsOn = tenant.subscription?.ends_at ? new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date(tenant.subscription.ends_at)) : '';
    const form = useForm({
        plan: tenant.subscription?.plan?.code ?? plans[0]?.code ?? '',
        period: tenant.subscription?.period ?? 'monthly',
        ends_at: endsOn,
        never_ends: Boolean(tenant.subscription?.unlimited),
        reason: '',
    });
    const isTrial = plans.find((plan) => plan.code === form.data.plan)?.is_trial;

    const submit = (event) => {
        event.preventDefault();
        form.put(`/tenants/${tenant.id}/subscription`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={form.processing ? undefined : onClose} fullWidth maxWidth="xs">
            <form onSubmit={submit}>
                <DialogTitle>Change plan for {tenant.name}</DialogTitle>
                <DialogContent dividers className="space-y-3">
                    <p className="text-sm text-slate-600">For corrections, extending a trial or a free month. Nothing is charged and no invoice is issued.</p>
                    <TextField select fullWidth size="small" label="Plan" value={form.data.plan} onChange={(event) => form.setData('plan', event.target.value)} error={Boolean(form.errors.plan)} helperText={form.errors.plan}>
                        {plans.map((option) => (
                            <MenuItem key={option.code} value={option.code}>
                                {option.name}
                            </MenuItem>
                        ))}
                    </TextField>
                    {!isTrial ? (
                        <TextField select fullWidth size="small" label="Period" value={form.data.period} onChange={(event) => form.setData('period', event.target.value)}>
                            {periods.map((option) => (
                                <MenuItem key={option.value} value={option.value}>
                                    {option.label}
                                </MenuItem>
                            ))}
                        </TextField>
                    ) : null}
                    <FormControlLabel control={<Checkbox checked={form.data.never_ends} onChange={(event) => form.setData('never_ends', event.target.checked)} />} label="Never ends" />
                    {!form.data.never_ends ? (
                        <TextField
                            type="date"
                            fullWidth
                            size="small"
                            label="Ends on"
                            value={form.data.ends_at}
                            onChange={(event) => form.setData('ends_at', event.target.value)}
                            error={Boolean(form.errors.ends_at)}
                            helperText={form.errors.ends_at ?? 'The plan runs to the end of this day (India time).'}
                            slotProps={{ inputLabel: { shrink: true } }}
                        />
                    ) : null}
                    <TextField
                        fullWidth
                        size="small"
                        label="Reason"
                        required
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        error={Boolean(form.errors.reason)}
                        helperText={form.errors.reason ?? 'Saved in the audit log.'}
                        slotProps={{ htmlInput: { maxLength: 250 } }}
                    />
                </DialogContent>
                <DialogActions>
                    <Button color="inherit" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        Save
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

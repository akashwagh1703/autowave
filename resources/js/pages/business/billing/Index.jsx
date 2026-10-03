import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import CheckIcon from '@mui/icons-material/Check';
import AppLayout from '@/layouts/AppLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';
import PayDialog from '@/modules/billing/PayDialog';
import { formatBytes, formatDate } from '@/utils/format';
import { LIMIT_ORDER, PAYMENT_COLORS, PAYMENT_STATUS_LABELS, STATE_COLORS, limitLabel, rupees } from '@/utils/billing';

function UsageBar({ label, used, limit, format = (value) => value }) {
    const unlimited = limit === null || limit === undefined;
    const percent = unlimited || limit <= 0 ? 0 : Math.min(100, Math.round((used * 100) / limit));

    return (
        <div>
            <div className="flex justify-between text-sm">
                <span className="text-slate-700">{label}</span>
                <span className="text-slate-500">
                    {format(used)} {unlimited ? '(unlimited)' : `of ${format(limit)}`}
                </span>
            </div>
            {!unlimited ? <LinearProgress variant="determinate" value={percent} color={percent >= 90 ? 'warning' : 'primary'} className="mt-1" /> : null}
        </div>
    );
}

function StatusAlert({ subscription, pending, timezone }) {
    if (pending) {
        return null;
    }

    switch (subscription.state) {
        case 'trial':
            return subscription.days_left <= 7 ? (
                <Alert severity="info">
                    Your free trial ends on {formatDate(subscription.ends_at, timezone)}. Choose a plan below to keep everything running. Paying now
                    does not cut your trial short.
                </Alert>
            ) : null;
        case 'active':
            return subscription.days_left <= 7 ? (
                <Alert severity="info">Your plan renews on {formatDate(subscription.ends_at, timezone)}. Pay now to avoid a break; the new period starts when this one ends.</Alert>
            ) : null;
        case 'due':
            return (
                <Alert severity="warning">
                    Your {subscription.is_trial ? 'trial' : 'plan'} ended on {formatDate(subscription.ends_at, timezone)}. Everything keeps working until{' '}
                    {formatDate(subscription.read_only_at, timezone)}; after that your business becomes read-only.
                </Alert>
            );
        case 'read_only':
            return (
                <Alert severity="error">
                    Your business is read-only: your team can see everything, but changes, messages and automations are paused. It will be locked on{' '}
                    {formatDate(subscription.locks_at, timezone)}. Pay below to restore everything straight away.
                </Alert>
            );
        case 'locked':
            return <Alert severity="error">Your business is locked and your website is offline. Nothing has been deleted: pay below to restore everything straight away.</Alert>;
        default:
            return null;
    }
}

export default function BillingIndex({ subscription, plans, periods, usage, methods, gst, reference, pending, payments, proof, canManage }) {
    const { timezone } = useTenant();
    const [period, setPeriod] = useState(subscription.period ?? 'monthly');
    const [paying, setPaying] = useState(null);
    const [cancelling, setCancelling] = useState(false);
    const [processing, setProcessing] = useState(false);
    const canPay = canManage && !pending && (methods.manual || methods.online) && !subscription.unlimited;

    const cancelPending = () => {
        router.post(`/settings/billing/payments/${pending.id}/cancel`, {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setCancelling(false);
            },
        });
    };

    return (
        <AppLayout title="Billing">
            <PageHeader
                title="Billing"
                description="Your AutoWave plan, what you are using, and your payments and invoices."
                actions={
                    <Button component={Link} href="/settings" variant="outlined">
                        Back to settings
                    </Button>
                }
            />

            <div className="space-y-4">
                <StatusAlert subscription={subscription} pending={pending} timezone={timezone} />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card variant="outlined">
                        <CardContent>
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-semibold text-slate-900">{subscription.plan?.name ?? 'No plan'}</h2>
                                <Chip size="small" variant="outlined" color={STATE_COLORS[subscription.state] ?? 'default'} label={subscription.label} />
                            </div>
                            {subscription.unlimited ? (
                                <p className="mt-2 text-sm text-slate-600">This business does not need a paid plan.</p>
                            ) : (
                                <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <dt className="text-xs text-slate-500 uppercase">{subscription.is_trial ? 'Trial ends' : 'Paid until'}</dt>
                                        <dd className="mt-0.5 text-slate-900">{formatDate(subscription.ends_at, timezone)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-slate-500 uppercase">Days left</dt>
                                        <dd className="mt-0.5 text-slate-900">{subscription.days_left}</dd>
                                    </div>
                                    {subscription.period ? (
                                        <div>
                                            <dt className="text-xs text-slate-500 uppercase">Billing</dt>
                                            <dd className="mt-0.5 text-slate-900 capitalize">{subscription.period}</dd>
                                        </div>
                                    ) : null}
                                    <div>
                                        <dt className="text-xs text-slate-500 uppercase">Payment reference</dt>
                                        <dd className="mt-0.5 font-mono text-slate-900">{reference}</dd>
                                    </div>
                                </dl>
                            )}
                            {subscription.next_plan ? (
                                <p className="mt-3 text-sm text-slate-600">
                                    Changes to <strong>{subscription.next_plan.name}</strong> ({subscription.next_period}) on {formatDate(subscription.plan_changes_at, timezone)}.
                                </p>
                            ) : null}
                            {pending ? (
                                <Alert severity="info" sx={{ mt: 1.5 }} action={canManage ? <Button color="inherit" size="small" onClick={() => setCancelling(true)}>Withdraw</Button> : null}>
                                    Your payment of {rupees(pending.total)} for {pending.plan} (UTR {pending.reference}) is being checked. We will email you once it is
                                    confirmed. Everything keeps working meanwhile.
                                </Alert>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent className="space-y-3">
                            <h2 className="font-semibold text-slate-900">Usage</h2>
                            <UsageBar label="Team members" used={usage.members.used} limit={usage.members.limit} />
                            <UsageBar label="Storage" used={usage.storage.used_bytes} limit={usage.storage.cap_bytes} format={formatBytes} />
                            <UsageBar label="AI this month" used={usage.ai.used} limit={usage.ai.cap} format={(value) => new Intl.NumberFormat('en-IN').format(value)} />
                            <UsageBar label="Active automations" used={usage.automations.used} limit={usage.automations.limit} />
                            <p className="text-sm text-slate-600">{usage.instagram ? 'Instagram inbox included.' : 'Instagram is not included in this plan.'}</p>
                        </CardContent>
                    </Card>
                </div>

                {!subscription.unlimited ? (
                    <Card variant="outlined">
                        <CardContent>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="font-semibold text-slate-900">Plans</h2>
                                <ToggleButtonGroup size="small" exclusive value={period} onChange={(event, value) => value && setPeriod(value)}>
                                    {periods.map((option) => (
                                        <ToggleButton key={option.value} value={option.value}>
                                            {option.label}
                                        </ToggleButton>
                                    ))}
                                </ToggleButtonGroup>
                            </div>
                            <p className="mt-1 text-sm text-slate-600">
                                Prices{gst ? ' exclude GST, which is added at checkout' : ' are final; no GST is charged'}. Yearly saves about two months.
                            </p>
                            <div className="mt-4 grid gap-4 md:grid-cols-3">
                                {plans.map((plan) => {
                                    const price = period === 'yearly' ? plan.price_yearly : plan.price_monthly;
                                    const current = subscription.next_plan
                                        ? subscription.next_plan.code === plan.code
                                        : subscription.plan?.code === plan.code && !subscription.is_trial;
                                    const waiting = subscription.next_plan && (subscription.next_plan.code !== plan.code || subscription.next_period !== period);

                                    return (
                                        <div key={plan.code} className={`flex flex-col rounded-lg border p-4 ${current ? 'border-brand-300 bg-brand-50' : 'border-slate-200'}`}>
                                            <div className="flex items-center justify-between gap-2">
                                                <h3 className="font-semibold text-slate-900">{plan.name}</h3>
                                                {current ? <Chip size="small" color="primary" label="Current" /> : null}
                                            </div>
                                            {plan.description ? <p className="mt-1 text-sm text-slate-600">{plan.description}</p> : null}
                                            <p className="mt-3 text-2xl font-bold text-slate-900">
                                                {rupees(price)}
                                                <span className="text-sm font-normal text-slate-500">/{period === 'yearly' ? 'year' : 'month'}</span>
                                            </p>
                                            <ul className="mt-3 flex-1 space-y-1 text-sm text-slate-700">
                                                {LIMIT_ORDER.map((key) => (
                                                    <li key={key} className="flex items-start gap-1.5">
                                                        <CheckIcon fontSize="inherit" className={`mt-1 ${key === 'instagram' && !plan.limits.instagram ? 'text-slate-300' : 'text-emerald-600'}`} />
                                                        {limitLabel(key, plan.limits[key])}
                                                    </li>
                                                ))}
                                            </ul>
                                            <Button sx={{ mt: 2 }} variant={current ? 'outlined' : 'contained'} disabled={!canPay || waiting} onClick={() => setPaying(plan)}>
                                                {current ? 'Renew' : 'Choose'}
                                            </Button>
                                            {waiting && current ? <p className="mt-1 text-xs text-slate-500">Switch to {subscription.next_period} billing to renew.</p> : null}
                                        </div>
                                    );
                                })}
                            </div>
                            {subscription.next_plan ? (
                                <p className="mt-3 text-sm text-slate-500">
                                    {subscription.next_plan.name} starts on {formatDate(subscription.plan_changes_at, timezone)}. You can renew it now, or switch plans after it starts.
                                </p>
                            ) : null}
                            {!canManage ? <p className="mt-3 text-sm text-slate-500">Only the business owner can choose a plan and pay.</p> : null}
                        </CardContent>
                    </Card>
                ) : null}

                <Card variant="outlined">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Payments</h2>
                        {payments.length === 0 ? (
                            <p className="mt-2 text-sm text-slate-600">No payments yet.</p>
                        ) : (
                            <div className="mt-2 overflow-x-auto">
                                <Table size="small">
                                    <TableHead>
                                        <TableRow>
                                            <TableCell>Date</TableCell>
                                            <TableCell>Plan</TableCell>
                                            <TableCell align="right">Amount</TableCell>
                                            <TableCell>Paid by</TableCell>
                                            <TableCell>Status</TableCell>
                                            <TableCell>Invoice</TableCell>
                                        </TableRow>
                                    </TableHead>
                                    <TableBody>
                                        {payments.map((payment) => (
                                            <TableRow key={payment.id}>
                                                <TableCell>{formatDate(payment.created_at, timezone)}</TableCell>
                                                <TableCell>
                                                    {payment.plan} <span className="text-slate-500">({payment.period})</span>
                                                </TableCell>
                                                <TableCell align="right">
                                                    {rupees(payment.total)}
                                                    {payment.discount > 0 ? (
                                                        <span className="block text-xs text-slate-500">
                                                            {payment.coupon_code} −{rupees(payment.discount)}
                                                        </span>
                                                    ) : null}
                                                </TableCell>
                                                <TableCell>
                                                    {payment.method_label}
                                                    {payment.reference ? <span className="block font-mono text-xs text-slate-500">{payment.reference}</span> : null}
                                                </TableCell>
                                                <TableCell>
                                                    <Chip size="small" variant="outlined" color={PAYMENT_COLORS[payment.status] ?? 'default'} label={PAYMENT_STATUS_LABELS[payment.status] ?? payment.status} />
                                                    {payment.rejection_reason ? <span className="mt-1 block text-xs text-red-700">{payment.rejection_reason}</span> : null}
                                                </TableCell>
                                                <TableCell>
                                                    {payment.invoice ? (
                                                        <>
                                                            <Link href={`/settings/billing/invoices/${payment.invoice.id}`} className="text-brand-700 hover:underline">
                                                                {payment.invoice.number}
                                                            </Link>
                                                            <a href={`/settings/billing/invoices/${payment.invoice.id}/pdf`} className="block text-xs text-slate-500 hover:underline">
                                                                PDF
                                                            </a>
                                                        </>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <PayDialog
                open={Boolean(paying)}
                plan={paying}
                period={period}
                methods={methods}
                gst={gst}
                reference={reference}
                proof={proof}
                subscription={subscription}
                timezone={timezone}
                onClose={() => setPaying(null)}
            />

            <ConfirmDialog
                open={cancelling}
                title="Withdraw this payment?"
                description="Do this only if you did not pay, or want to pay another way. If the money was sent, contact AutoWave support instead."
                confirmLabel="Withdraw"
                destructive
                processing={processing}
                onConfirm={cancelPending}
                onClose={() => setCancelling(false)}
            />
        </AppLayout>
    );
}

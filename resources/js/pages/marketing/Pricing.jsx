import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import CheckIcon from '@mui/icons-material/Check';
import PublicLayout from '@/layouts/PublicLayout';
import { LIMIT_ORDER, limitLabel, rupees } from '@/utils/billing';

const linkClass = 'text-brand-700 hover:underline';

export default function Pricing({ plans, appUrl, billing }) {
    const [period, setPeriod] = useState('monthly');
    const popular = plans.length > 2 ? plans[1].code : null;

    return (
        <PublicLayout>
            <Head title="Pricing" />
            <section className="mx-auto max-w-5xl px-4 pt-10 pb-20 sm:px-6">
                <div className="text-center">
                    <h1 className="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl">Simple plans for every local business</h1>
                    <p className="mx-auto mt-3 max-w-2xl text-slate-600">
                        Start with a free {billing.trial_days}-day trial, no payment details needed. Then pay monthly or yearly; plans never
                        renew automatically.
                    </p>
                    <ToggleButtonGroup exclusive size="small" value={period} onChange={(event, value) => value && setPeriod(value)} sx={{ mt: 3 }}>
                        <ToggleButton value="monthly">Monthly</ToggleButton>
                        <ToggleButton value="yearly">Yearly · 2 months free</ToggleButton>
                    </ToggleButtonGroup>
                </div>

                {plans.length === 0 ? (
                    <p className="mt-10 text-center text-slate-600">
                        Plans are being updated.{' '}
                        <Link href="/contact" className={linkClass}>
                            Contact us
                        </Link>{' '}
                        for current prices.
                    </p>
                ) : null}

                <div className="mt-10 grid gap-4 md:grid-cols-3">
                    {plans.map((plan) => {
                        const price = period === 'yearly' ? plan.price_yearly : plan.price_monthly;

                        return (
                            <Card key={plan.code} variant="outlined" sx={plan.code === popular ? { borderColor: 'primary.main', borderWidth: 2 } : undefined}>
                                <CardContent className="flex h-full flex-col">
                                    <div className="flex items-center gap-2">
                                        <h2 className="text-lg font-semibold text-slate-900">{plan.name}</h2>
                                        {plan.code === popular ? <Chip size="small" color="primary" label="Most popular" /> : null}
                                    </div>
                                    {plan.description ? <p className="mt-1 text-sm text-slate-600">{plan.description}</p> : null}
                                    <p className="mt-4">
                                        <span className="text-3xl font-bold text-slate-900">{rupees(price)}</span>
                                        <span className="text-slate-500"> / {period === 'yearly' ? 'year' : 'month'}</span>
                                    </p>
                                    {period === 'yearly' ? <p className="text-xs text-slate-500">{rupees(Math.round(plan.price_yearly / 12))} a month, billed yearly</p> : null}
                                    <ul className="mt-4 flex-1 space-y-1.5 text-sm text-slate-700">
                                        {LIMIT_ORDER.map((key) => (
                                            <li key={key} className="flex items-start gap-2">
                                                <CheckIcon fontSize="small" className="mt-0.5 text-emerald-600" />
                                                <span>{limitLabel(key, plan.limits[key])}</span>
                                            </li>
                                        ))}
                                    </ul>
                                    <Button variant={plan.code === popular ? 'contained' : 'outlined'} href={`${appUrl}/register`} fullWidth sx={{ mt: 3 }}>
                                        Start free trial
                                    </Button>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                <div className="mx-auto mt-10 max-w-3xl space-y-2 text-center text-sm text-slate-600">
                    <p>Every plan includes the business website, CRM, bookings, orders, the messaging inbox, automations and AI tools.</p>
                    <p>
                        {billing.gst ? `Prices exclude GST at ${billing.gst_rate}%, added on your tax invoice.` : 'No GST is charged at present.'}{' '}
                        {billing.online ? 'Pay online with UPI, card, net banking or wallet, or by UPI or bank transfer.' : 'Pay by UPI or bank transfer.'}{' '}
                        Upgrade any time with credit for unused days.
                    </p>
                    <p>
                        See our{' '}
                        <Link href="/refunds" className={linkClass}>
                            Refund and cancellation policy
                        </Link>{' '}
                        and{' '}
                        <Link href="/terms" className={linkClass}>
                            Terms
                        </Link>
                        . Questions?{' '}
                        <Link href="/contact" className={linkClass}>
                            Contact us
                        </Link>
                        .
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}

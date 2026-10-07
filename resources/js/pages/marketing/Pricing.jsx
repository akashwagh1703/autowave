import { Link } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import CheckIcon from '@mui/icons-material/Check';
import PublicLayout from '@/layouts/PublicLayout';
import { Container, CtaBand, Eyebrow, Faq, SectionHeader } from '@/modules/marketing/blocks';
import { LIMIT_ORDER, plainLimitLabel, rupees } from '@/utils/billing';

const linkClass = 'text-brand-700 hover:underline';

function goodFor(members) {
    if (members === null || members === undefined || members > 5) {
        return 'Good for bigger teams and busy businesses';
    }

    return members <= 2 ? 'Good for an owner with one helper' : 'Good for a growing shop with a small team';
}

export default function Pricing({ plans, appUrl, billing }) {
    const [period, setPeriod] = useState('monthly');
    const popular = plans.length > 2 ? plans[1].code : null;

    return (
        <PublicLayout>
            <section className="mx-auto max-w-5xl px-4 pt-12 pb-16 sm:px-6">
                <div className="text-center">
                    <Eyebrow>Pricing</Eyebrow>
                    <h1 className="font-display mt-2 text-4xl font-extrabold tracking-tight text-ink sm:text-5xl">Simple plans for every local business</h1>
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
                            <Card
                                key={plan.code}
                                variant="outlined"
                                sx={{ borderRadius: 4, ...(plan.code === popular ? { borderColor: 'primary.main', borderWidth: 2, boxShadow: '0 20px 40px -20px rgba(67, 56, 202, 0.35)' } : {}) }}
                            >
                                <CardContent className="flex h-full flex-col">
                                    <div className="flex items-center gap-2">
                                        <h2 className="font-display text-lg font-bold text-ink">{plan.name}</h2>
                                        {plan.code === popular ? <Chip size="small" color="primary" label="Most popular" /> : null}
                                    </div>
                                    <p className="mt-1 text-sm font-medium text-brand-700">{goodFor(plan.limits.members)}</p>
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
                                                <span>{plainLimitLabel(key, plan.limits[key])}</span>
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
                    <p>
                        Every plan includes your website, online bookings and orders, your customer list, the WhatsApp assistant, all your
                        messages in one place, automatic reminders and AI help.
                    </p>
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

            <section className="bg-slate-50 py-16">
                <Container>
                    <SectionHeader title="Billing questions" />
                    <div className="mt-10">
                        <Faq
                            items={[
                                { q: 'Do I need a card to start the trial?', a: `No. The ${billing.trial_days}-day trial needs no payment details. Pick a plan whenever you are ready.` },
                                { q: 'Will I be charged automatically?', a: 'No. Plans never renew automatically. We remind you before your plan ends and you renew only if you want to.' },
                                { q: 'Can I change plans later?', a: 'Yes. Upgrade any time and the unused days of your current plan are credited towards the new one.' },
                                {
                                    q: 'What happens if my plan ends?',
                                    a: `You get ${billing.grace_days} days of grace to renew. After that the app becomes read-only, and after ${billing.lock_after_days} days your website goes offline until you renew. Your data is kept safe.`,
                                },
                                {
                                    q: 'What are AI credits?',
                                    a: 'AI uses credits each time it writes something for you, such as a reply, an offer or website text, or answers a customer on WhatsApp. Longer texts use more. You can see how many you have used in Settings → AI, where credits are shown as tokens.',
                                },
                                {
                                    q: 'Do I pay WhatsApp for messages?',
                                    a: 'Replies to customers who message you, including the assistant’s replies, are free on WhatsApp. Meta (the company behind WhatsApp) charges a small fee for some messages your business starts, such as reminders, billed to your own Meta account.',
                                },
                            ]}
                        />
                    </div>
                </Container>
            </section>

            <div className="pt-16">
                <CtaBand trialDays={billing.trial_days} />
            </div>
        </PublicLayout>
    );
}

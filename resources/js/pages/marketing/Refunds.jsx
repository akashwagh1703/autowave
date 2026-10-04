import { Link } from '@inertiajs/react';
import LegalPage, { CompanyDetails, Email, List, Section } from '@/modules/marketing/LegalPage';

export default function Refunds({ company, legal, billing }) {
    return (
        <LegalPage
            title="Refund and cancellation policy"
            updated={legal.updated}
            intro={
                <p>
                    This policy explains how cancelling an AutoWave plan works, when we refund a payment and how the service is delivered.
                    It is part of our{' '}
                    <Link href="/terms" className="text-brand-700 hover:underline">
                        Terms of service
                    </Link>
                    .
                </p>
            }
        >
            <Section title="1. Free trial">
                <p>
                    The {billing.trial_days}-day trial is free and needs no payment details, so there is nothing to cancel or refund.
                </p>
            </Section>

            <Section title="2. Cancelling a plan">
                <List
                    items={[
                        'Plans never renew or charge you automatically. To cancel, simply don’t pay for the next period.',
                        'Your plan stays fully active until the end of the period you paid for. There is no cancellation fee.',
                        `After it ends you keep access for ${billing.grace_days} more days, then the account may become read-only and later locked, with your data kept. You can pay again at any time to continue.`,
                        'A UPI or bank payment you reported can be withdrawn from Billing while it is still waiting for approval. If you already sent the money, write to us and we will refund it.',
                    ]}
                />
            </Section>

            <Section title="3. When we refund">
                <p>
                    A plan payment is for a period of service that starts when it is activated, so we don’t refund the unused part of a
                    period once it has started. We do refund in full when:
                </p>
                <List
                    items={[
                        'you paid twice for the same period;',
                        'money left your account but the plan could not be activated;',
                        'we did not approve your UPI or bank payment but the money reached us;',
                        'the law requires a refund.',
                    ]}
                />
                <p>
                    Upgrades are not refunded; instead, the unused days of your current plan are credited against the price of the new
                    one. Downgrades take effect when your current period ends.
                </p>
            </Section>

            <Section title="4. How to ask for a refund">
                <p>
                    Email <Email address={company.email} /> from your account’s email address within {legal.refund_request_days} days of the
                    payment, with:
                </p>
                <List
                    items={[
                        'your customer ID (shown in Settings → Plan and billing, e.g. AW-123);',
                        'the date and amount of the payment;',
                        'the UTR, bank reference or Razorpay payment ID.',
                    ]}
                />
            </Section>

            <Section title="5. How refunds are paid">
                <p>
                    We reply within 2 working days. An approved refund is sent within {legal.refund_days} working days to the way you paid:
                    online payments back to the original card, UPI or bank account through Razorpay, and UPI or bank transfers to the
                    account the money came from. Your bank may take a few more days to show it.
                </p>
            </Section>

            <Section title="6. Delivery of the service">
                <p>
                    AutoWave is an online service; nothing is shipped. An online payment activates your plan as soon as it is confirmed. A
                    UPI or bank payment activates it once we have verified it, usually within one working day. Your invoice is emailed to
                    you and stays available in Billing.
                </p>
            </Section>

            <Section title="7. Contact">
                <CompanyDetails company={company} />
            </Section>
        </LegalPage>
    );
}

import { Link } from '@inertiajs/react';
import LegalPage, { CompanyDetails, List, Section } from '@/modules/marketing/LegalPage';

const linkClass = 'text-brand-700 hover:underline';

export default function Terms({ company, legal, billing }) {
    return (
        <LegalPage
            title="Terms of service"
            updated={legal.updated}
            intro={
                <p>
                    These terms are an agreement between you and {company.name} (“AutoWave”, “we”, “us”) for using AutoWave. By creating an
                    account or using the service you accept them, on your own behalf and for the business you add. If you don’t agree,
                    please don’t use AutoWave.
                </p>
            }
        >
            <Section title="1. The service">
                <p>
                    AutoWave gives local businesses a website, CRM, bookings, orders, a messaging inbox, automations and AI tools in one
                    app. We improve AutoWave all the time, so features may change; we won’t remove a core feature of a paid plan during a
                    period you have already paid for.
                </p>
            </Section>

            <Section title="2. Your account">
                <List
                    items={[
                        'You must be at least 18 and able to enter a contract for the business you add.',
                        'Give accurate details and keep them up to date, especially your email address and GSTIN.',
                        'Keep your password safe. You are responsible for what happens in your account, including what your team members do.',
                        'Tell us straight away if you think someone else has used your account.',
                    ]}
                />
            </Section>

            <Section title="3. Free trial">
                <p>
                    Every new business gets a free {billing.trial_days}-day trial with no payment details asked. When the trial ends, choose
                    a plan to keep using AutoWave.
                </p>
            </Section>

            <Section title="4. Plans and payment">
                <List
                    items={[
                        <>
                            Plans and prices are on our{' '}
                            <Link href="/pricing" className={linkClass}>
                                Pricing
                            </Link>{' '}
                            page, in Indian rupees, for a month or a year, paid in advance.
                        </>,
                        'Plans do not renew or charge you automatically. You pay for each period yourself; we email reminders before it ends.',
                        'You can pay by UPI or bank transfer, which activates the plan once we have verified the payment, or, where offered, online through Razorpay (UPI, card, net banking or wallet), which activates it as soon as the payment is confirmed.',
                        billing.gst
                            ? `GST at ${billing.gst_rate}% is added where it applies and shown on your tax invoice.`
                            : 'We are not registered for GST at present, so no GST is charged. If that changes, GST will be added to later payments and shown on the invoice.',
                        'An invoice is issued and emailed for every paid period.',
                        'Coupons apply only as described when they are issued, can’t be exchanged for cash and may be withdrawn at any time.',
                        'We may change prices; a change applies from your next payment, not to a period already paid.',
                    ]}
                />
            </Section>

            <Section title="5. Changing plans">
                <p>
                    Upgrading starts straight away, and the unused days of your current paid plan are credited against the new price.
                    Downgrades and switches between monthly and yearly start when your current period ends.
                </p>
            </Section>

            <Section title="6. If a plan is not renewed">
                <p>
                    After your plan’s end date you keep full access for {billing.grace_days} days. After that we may make the account
                    read-only, and after {billing.lock_after_days} days lock it so that only Billing opens and your business website goes
                    offline. Your data stays in place; paying for a plan restores access.
                </p>
            </Section>

            <Section title="7. Cancellation and refunds">
                <p>
                    You can stop using AutoWave at any time by not paying for the next period; your plan stays active until the end of the
                    period you paid for. Refunds are covered by our{' '}
                    <Link href="/refunds" className={linkClass}>
                        Refund and cancellation policy
                    </Link>
                    .
                </p>
            </Section>

            <Section title="8. Your content and your customers’ data">
                <List
                    items={[
                        'You own the content and data you put into AutoWave. You let us store, process and display it only to provide the service to you.',
                        'For your customers’ personal data you are the data fiduciary and we process it on your instructions, as described in our Privacy policy. You are responsible for collecting it lawfully and for having consent to contact your customers.',
                        'You can ask for a copy of your business data or for it to be deleted at any time.',
                    ]}
                />
            </Section>

            <Section title="9. Acceptable use">
                <p>You must not use AutoWave to:</p>
                <List
                    items={[
                        'send spam or messages to people who have not agreed to hear from you, or ignore opt-outs;',
                        'break the WhatsApp Business, Meta or other platform policies that apply to the channels you connect;',
                        'publish or send anything illegal, misleading, hateful, infringing or harmful;',
                        'impersonate anyone, or collect data you are not allowed to collect;',
                        'try to break, overload or get around the security or limits of AutoWave, or access other businesses’ data;',
                        'resell or copy the service without our written permission.',
                    ]}
                />
                <p>We may remove content or suspend an account that breaks these rules, telling you why where we can.</p>
            </Section>

            <Section title="10. Other services you connect">
                <p>
                    WhatsApp, Instagram, Razorpay, email providers and AI providers are run by other companies under their own terms. We
                    are not responsible for their availability, or for decisions they make about your accounts with them.
                </p>
            </Section>

            <Section title="11. AI features">
                <p>
                    AI suggestions, summaries and drafts can be wrong or incomplete. Check them before you rely on them or send them to
                    anyone; AutoWave never sends an AI-written message to a customer without a person choosing to.
                </p>
            </Section>

            <Section title="12. Availability and support">
                <p>
                    We work to keep AutoWave available and your data safe, but the service may sometimes be interrupted for maintenance or
                    by problems outside our control. Contact us by email for support.
                </p>
            </Section>

            <Section title="13. Suspension and termination">
                <p>
                    We may suspend or close an account that breaks these terms, is not paid for as described above, or when the law
                    requires it. You can close your account at any time by writing to us. When an account is closed, its data is deleted
                    as described in our Privacy policy, except records we must keep by law.
                </p>
            </Section>

            <Section title="14. Liability">
                <p>
                    AutoWave is provided “as is”. To the extent the law allows, we are not liable for indirect or consequential losses
                    such as lost profits, lost business or lost data, and our total liability for any claim is limited to what you paid us
                    for AutoWave in the 12 months before the claim. Nothing in these terms limits liability that cannot be limited by law.
                </p>
                <p>
                    You agree to compensate us for claims made against us because of content you put into AutoWave or your breach of
                    these terms.
                </p>
            </Section>

            <Section title="15. Law and disputes">
                <p>
                    These terms are governed by the laws of India.{' '}
                    {legal.jurisdiction
                        ? `The courts at ${legal.jurisdiction} have exclusive jurisdiction over any dispute.`
                        : 'The courts having jurisdiction over our registered office have exclusive jurisdiction over any dispute.'}{' '}
                    Before going to court, please write to us so we can try to resolve the issue.
                </p>
            </Section>

            <Section title="16. Changes to these terms">
                <p>
                    We may update these terms. We will post the new version here with a new date and, for important changes, tell you by
                    email or in the app before they apply. Using AutoWave after that means you accept the new terms.
                </p>
            </Section>

            <Section title="17. Contact">
                <CompanyDetails company={company} />
            </Section>
        </LegalPage>
    );
}

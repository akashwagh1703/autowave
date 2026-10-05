import { Link } from '@inertiajs/react';
import LegalPage, { CompanyDetails, Email, List, Section } from '@/modules/marketing/LegalPage';

export default function Privacy({ company, legal }) {
    return (
        <LegalPage
            title="Privacy policy"
            updated={legal.updated}
            intro={
                <>
                    <p>
                        {company.name} (“AutoWave”, “we”, “us”) runs AutoWave: the website at this address, the business app and the
                        websites businesses publish with it. This policy explains what personal data we handle, why, who we share it with
                        and the choices you have. It is written for the Digital Personal Data Protection Act, 2023 and the Information
                        Technology Act, 2000 of India.
                    </p>
                    <p>
                        Two kinds of people use AutoWave: <strong>business users</strong> (owners and their team, who sign up and run a
                        business in the app) and <strong>their customers</strong> (people who book, order, enquire or message those
                        businesses). For business users, we decide how their account data is used. For their customers, the business
                        decides; we process that data on the business’s behalf.
                    </p>
                </>
            }
        >
            <Section title="1. Data we collect">
                <List
                    items={[
                        <>
                            <strong>Account details:</strong> your name, email address and password (stored only as a one-way hash), and the
                            businesses and roles you belong to.
                        </>,
                        <>
                            <strong>Business details:</strong> business name, type, address, phone, GSTIN, logo, colours and website content.
                        </>,
                        <>
                            <strong>Data a business adds about its customers:</strong> leads, customers, appointments, orders, students, fees,
                            reservations, notes, files and the WhatsApp, Instagram and email messages exchanged with them.
                        </>,
                        <>
                            <strong>Plan payments:</strong> the plan, amount, payment method, UTR or payment reference, any payment screenshot
                            you upload, and your invoices. Online payments are handled by Razorpay: we receive the payment ID and its status,
                            never your card number, UPI PIN or bank password.
                        </>,
                        <>
                            <strong>Demo requests:</strong> the name, phone number, email, business details and message you send through “Book a
                            demo”, used only to contact you about AutoWave.
                        </>,
                        <>
                            <strong>Technical data:</strong> IP address, browser and device type, and logs of requests and security-relevant
                            actions (sign-ins, payments, settings changes).
                        </>,
                    ]}
                />
            </Section>

            <Section title="2. Cookies">
                <p>
                    We use only the cookies needed to run the service: one that keeps you signed in and one that protects forms against
                    cross-site request forgery. We do not use advertising or third-party analytics cookies.
                </p>
            </Section>

            <Section title="3. How we use it">
                <List
                    items={[
                        'To provide AutoWave: your dashboard, website, bookings, orders, messages, automations and AI features.',
                        'To take payments for your plan, issue invoices and send reminders before your plan ends.',
                        'To send service emails, such as email verification, password resets, payment confirmations and important changes.',
                        'To keep the service secure, prevent abuse and investigate problems.',
                        'To give support when you contact us.',
                        'To meet legal duties, such as keeping invoices and tax records.',
                    ]}
                />
                <p>We do not sell personal data and we do not show advertising.</p>
            </Section>

            <Section title="4. Who we share it with">
                <p>We share data only with providers that help us run AutoWave, and only what each one needs:</p>
                <List
                    items={[
                        <>
                            <strong>Cloud hosting</strong>: the servers and storage where AutoWave and its files run.
                        </>,
                        <>
                            <strong>Razorpay</strong>: to process online payments for plans.
                        </>,
                        <>
                            <strong>Email delivery provider</strong>: to send account, billing and automation emails.
                        </>,
                        <>
                            <strong>Meta (WhatsApp and Instagram)</strong>: when a business connects its own WhatsApp Business or Instagram
                            account, messages to and from its customers pass through Meta.
                        </>,
                        <>
                            <strong>OpenRouter and the AI model providers it uses</strong>: when a business uses an AI feature (reply
                            suggestions, summaries, the assistant, writing help), the text the feature needs, such as recent messages or
                            customer notes, is sent to generate a result. AI results are suggestions; a person decides what is sent.
                        </>,
                    ]}
                />
                <p>
                    Some of these providers may process data outside India. We may also disclose data when the law requires it, or to
                    protect the rights, property or safety of our users or the public.
                </p>
            </Section>

            <Section title="5. Customers of businesses on AutoWave">
                <p>
                    If you booked, ordered, enquired or chatted with a business that uses AutoWave, that business is responsible for your
                    data and for having your consent to message you. Please contact the business first to see, correct or delete your
                    data. You can stop WhatsApp messages from a business by replying STOP. If you cannot reach the business, write to us
                    and we will help.
                </p>
            </Section>

            <Section title="6. How long we keep it">
                <List
                    items={[
                        'Account and business data: while the account is open. When a business asks us to close it, we delete its data, including its customers’ data.',
                        'Invoices and payment records: for as long as tax and accounting laws require, even after an account is closed.',
                        'Security and request logs: only as long as needed to keep the service secure.',
                    ]}
                />
            </Section>

            <Section title="7. Your rights">
                <p>You can ask us to:</p>
                <List
                    items={[
                        'give you a summary of the personal data we hold about you and who we shared it with;',
                        'correct or complete it (most details can also be edited in the app);',
                        'delete it, where we don’t have to keep it by law;',
                        'withdraw consent you gave us, without affecting what was done before;',
                        'nominate another person to exercise these rights if you die or become unable to.',
                    ]}
                />
                <p>
                    Write to <Email address={company.email} /> from the email address on your account. We aim to respond within 30
                    days.
                </p>
            </Section>

            <Section title="8. Deleting your data">
                <p>
                    To close your account or delete a business and its data, email <Email address={company.email} /> from your account’s
                    email address with the business name. We will confirm the request and tell you when it is done. To stop AutoWave from
                    receiving your WhatsApp or Instagram messages, remove the connection in Settings → Messaging.
                </p>
            </Section>

            <Section title="9. Security">
                <p>
                    AutoWave is served only over HTTPS. Passwords are hashed, WhatsApp and Instagram access tokens are encrypted, each
                    business’s data is kept separate, team members see only what their role allows, and sensitive actions are logged. No
                    system is perfectly secure; if a breach affects your data, we will tell you and the authorities as the law requires.
                </p>
            </Section>

            <Section title="10. Children">
                <p>
                    Business accounts are for people aged 18 or over. Businesses that hold data about children, such as coaching centres
                    with students, must have the consent of a parent or guardian.
                </p>
            </Section>

            <Section title="11. Changes to this policy">
                <p>
                    We will post any change here with a new date. If a change matters to how your data is used, we will also tell business
                    users by email or in the app.
                </p>
            </Section>

            <Section title="12. Contact and grievances">
                <p>Questions or complaints about privacy go to our Grievance Officer:</p>
                <CompanyDetails company={company} grievanceOfficer={legal.grievance_officer} />
                <p>
                    If you are not satisfied with our answer, you can complain to the Data Protection Board of India. See also our{' '}
                    <Link href="/terms" className="text-brand-700 hover:underline">
                        Terms of service
                    </Link>
                    .
                </p>
            </Section>
        </LegalPage>
    );
}

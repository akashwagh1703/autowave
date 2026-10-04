import { Head, Link } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import PublicLayout from '@/layouts/PublicLayout';
import { CompanyDetails } from '@/modules/marketing/LegalPage';

const topics = [
    { title: 'Help with the app', body: 'Tell us your business name and what you were trying to do. A screenshot helps.' },
    { title: 'Plans and payments', body: 'Include your customer ID (Settings → Plan and billing, e.g. AW-123) and the UTR or payment ID.' },
    { title: 'Privacy and your data', body: 'Write from the email address on your account so we can verify it is you.' },
];

export default function Contact({ company, legal }) {
    return (
        <PublicLayout>
            <Head title="Contact us" />
            <div className="mx-auto max-w-3xl px-4 pt-10 pb-20 sm:px-6">
                <h1 className="text-3xl font-bold tracking-tight text-slate-900">Contact us</h1>
                <p className="mt-3 text-slate-600">We usually reply within one working day.</p>

                <div className="mt-6">
                    <CompanyDetails company={company} grievanceOfficer={legal.grievance_officer} />
                </div>

                <div className="mt-8 grid gap-4 sm:grid-cols-3">
                    {topics.map((topic) => (
                        <Card key={topic.title} variant="outlined">
                            <CardContent>
                                <h2 className="font-semibold text-slate-900">{topic.title}</h2>
                                <p className="mt-1 text-sm text-slate-600">{topic.body}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <p className="mt-8 text-sm text-slate-600">
                    See also our{' '}
                    <Link href="/refunds" className="text-brand-700 hover:underline">
                        Refund and cancellation policy
                    </Link>
                    ,{' '}
                    <Link href="/terms" className="text-brand-700 hover:underline">
                        Terms
                    </Link>{' '}
                    and{' '}
                    <Link href="/privacy" className="text-brand-700 hover:underline">
                        Privacy policy
                    </Link>
                    .
                </p>
            </div>
        </PublicLayout>
    );
}

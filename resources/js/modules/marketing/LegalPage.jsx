import { Head } from '@inertiajs/react';
import PublicLayout from '@/layouts/PublicLayout';
import { formatDate } from '@/utils/format';

/** A policy page on the marketing site: title, last-updated date and numbered sections. */
export default function LegalPage({ title, updated, intro, children }) {
    return (
        <PublicLayout>
            <Head title={title} />
            <article className="mx-auto max-w-3xl px-4 pt-10 pb-20 text-slate-700 sm:px-6">
                <h1 className="text-3xl font-bold tracking-tight text-slate-900">{title}</h1>
                {updated ? <p className="mt-2 text-sm text-slate-500">Last updated {formatDate(updated, 'UTC')}</p> : null}
                {intro ? <div className="mt-6 space-y-3 leading-relaxed">{intro}</div> : null}
                <div className="mt-8 space-y-8">{children}</div>
            </article>
        </PublicLayout>
    );
}

export function Section({ title, children }) {
    return (
        <section>
            <h2 className="text-lg font-semibold text-slate-900">{title}</h2>
            <div className="mt-2 space-y-3 leading-relaxed">{children}</div>
        </section>
    );
}

export function List({ items }) {
    return (
        <ul className="list-disc space-y-1.5 pl-5">
            {items.filter(Boolean).map((item, index) => (
                <li key={index}>{item}</li>
            ))}
        </ul>
    );
}

export function Email({ address }) {
    return address ? (
        <a href={`mailto:${address}`} className="font-medium text-brand-700 hover:underline">
            {address}
        </a>
    ) : (
        <span>the email address on our Contact page</span>
    );
}

/** Who runs AutoWave, from Super Admin → Settings → Billing. */
export function CompanyDetails({ company, grievanceOfficer = null }) {
    return (
        <address className="rounded-lg border border-slate-200 bg-white p-4 not-italic">
            <p className="font-semibold text-slate-900">{company.name}</p>
            {company.address ? <p className="whitespace-pre-line">{company.address}</p> : null}
            {company.email ? (
                <p>
                    Email: <Email address={company.email} />
                </p>
            ) : null}
            {company.phone ? (
                <p>
                    Phone:{' '}
                    <a href={`tel:${company.phone.replace(/\s+/g, '')}`} className="text-brand-700 hover:underline">
                        {company.phone}
                    </a>
                </p>
            ) : null}
            {company.gstin ? <p>GSTIN: {company.gstin}</p> : null}
            {grievanceOfficer ? <p>Grievance Officer: {grievanceOfficer}</p> : null}
        </address>
    );
}

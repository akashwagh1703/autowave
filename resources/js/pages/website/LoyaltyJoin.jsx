import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function LoyaltyJoin({ business, heading, blurb, stamps_required, reward_label, collect_birthday }) {
    const form = useForm({ name: '', phone: '', date_of_birth: '', company_website: '' });
    const [submitted, setSubmitted] = useState(false);

    const submit = (event) => {
        event.preventDefault();
        form.post('/loyalty/join', {
            preserveScroll: true,
            onSuccess: () => setSubmitted(true),
        });
    };

    return (
        <div className="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 antialiased">
            <Head title={heading} />
            <div className="mx-auto max-w-md space-y-6">
                <header className="space-y-2 text-center">
                    <p className="text-sm font-medium uppercase tracking-wide text-slate-500">{business.name}</p>
                    <h1 className="text-2xl font-semibold tracking-tight">{heading}</h1>
                    <p className="text-sm text-slate-600">{blurb}</p>
                    <p className="text-sm text-slate-700">
                        Collect {stamps_required} stamps → {reward_label}
                    </p>
                </header>

                <form onSubmit={submit} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" noValidate>
                    <input type="text" name="company_website" value={form.data.company_website} onChange={(event) => form.setData('company_website', event.target.value)} className="hidden" tabIndex={-1} autoComplete="off" />
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">Name</span>
                        <input
                            className="w-full rounded-lg border border-slate-300 px-3 py-2"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            required
                        />
                        {form.errors.name ? <span className="mt-1 block text-red-600">{form.errors.name}</span> : null}
                    </label>
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">Phone</span>
                        <input
                            className="w-full rounded-lg border border-slate-300 px-3 py-2"
                            value={form.data.phone}
                            onChange={(event) => form.setData('phone', event.target.value)}
                            required
                            inputMode="tel"
                        />
                        {form.errors.phone ? <span className="mt-1 block text-red-600">{form.errors.phone}</span> : null}
                    </label>
                    {collect_birthday ? (
                        <label className="block text-sm">
                            <span className="mb-1 block font-medium">Birthday</span>
                            <input
                                type="date"
                                className="w-full rounded-lg border border-slate-300 px-3 py-2"
                                value={form.data.date_of_birth}
                                onChange={(event) => form.setData('date_of_birth', event.target.value)}
                                required
                            />
                            {form.errors.date_of_birth ? <span className="mt-1 block text-red-600">{form.errors.date_of_birth}</span> : null}
                            <span className="mt-1 block text-xs text-slate-500">So we can treat you on your birthday.</span>
                        </label>
                    ) : null}
                    {form.errors.loyalty ? <p className="text-sm text-red-600">{form.errors.loyalty}</p> : null}
                    <button type="submit" disabled={form.processing || submitted} className="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-60">
                        {form.processing ? 'Joining…' : 'Join rewards'}
                    </button>
                </form>
            </div>
        </div>
    );
}

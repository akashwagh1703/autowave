import { Head, useForm, usePage } from '@inertiajs/react';

export default function CustomerBirthday({ business, customer }) {
    const { flash } = usePage().props;
    const form = useForm({ date_of_birth: '' });
    const saved = Boolean(flash?.success) || customer.has_birthday;

    const submit = (event) => {
        event.preventDefault();
        form.post(window.location.pathname + window.location.search, { preserveScroll: true });
    };

    return (
        <div className="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 antialiased">
            <Head title="Birthday" />
            <div className="mx-auto max-w-md space-y-6">
                <header className="space-y-2 text-center">
                    <p className="text-sm font-medium uppercase tracking-wide text-slate-500">{business.name}</p>
                    <h1 className="text-2xl font-semibold tracking-tight">Save your birthday</h1>
                    <p className="text-sm text-slate-600">
                        {customer.name ? `Hi ${customer.name.split(' ')[0]}, ` : ''}
                        tell us your date of birth so we can treat you on your birthday.
                    </p>
                </header>

                {saved ? (
                    <p className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center text-sm text-emerald-900">
                        {flash?.success || 'Thanks — we already have your birthday.'}
                    </p>
                ) : (
                    <form onSubmit={submit} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" noValidate>
                        <label className="block text-sm">
                            <span className="mb-1 block font-medium">Date of birth</span>
                            <input
                                type="date"
                                className="w-full rounded-lg border border-slate-300 px-3 py-2"
                                value={form.data.date_of_birth}
                                onChange={(event) => form.setData('date_of_birth', event.target.value)}
                                required
                            />
                            {form.errors.date_of_birth ? <span className="mt-1 block text-red-600">{form.errors.date_of_birth}</span> : null}
                        </label>
                        <button type="submit" disabled={form.processing} className="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-60">
                            {form.processing ? 'Saving…' : 'Save birthday'}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}

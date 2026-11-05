import { Head } from '@inertiajs/react';

export default function LoyaltyCard({ business, customer, loyalty }) {
    const filled = Math.min(loyalty.stamps_balance, loyalty.stamps_required);
    const slots = Array.from({ length: loyalty.stamps_required }, (_, index) => index < filled);

    return (
        <div className="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 antialiased">
            <Head title="Rewards card" />
            <div className="mx-auto max-w-md space-y-6">
                <header className="space-y-1 text-center">
                    <p className="text-sm font-medium uppercase tracking-wide text-slate-500">{business.name}</p>
                    <h1 className="text-2xl font-semibold tracking-tight">Rewards card</h1>
                    {customer?.name ? <p className="text-slate-600">{customer.name}</p> : null}
                </header>

                <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p className="text-center text-sm text-slate-600">
                        {loyalty.stamps_balance} / {loyalty.stamps_required} stamps
                        {loyalty.rewards_available > 0 ? ` · ${loyalty.rewards_available} × ${loyalty.reward_label} ready` : ''}
                    </p>
                    <div className="grid grid-cols-5 gap-2">
                        {slots.map((done, index) => (
                            <div
                                key={index}
                                className={`flex aspect-square items-center justify-center rounded-full border text-sm font-semibold ${done ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200 text-slate-300'}`}
                            >
                                {done ? '✓' : index + 1}
                            </div>
                        ))}
                    </div>
                    <p className="text-center text-sm text-slate-700">
                        Reward: <span className="font-medium">{loyalty.reward_label}</span>
                    </p>
                    <p className="text-center text-xs text-slate-500">Show this page at the counter when you visit.</p>
                </section>
            </div>
        </div>
    );
}

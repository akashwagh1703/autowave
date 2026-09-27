import { Head } from '@inertiajs/react';
import { humanize } from '@/utils/format';

export default function Home({ business, sections }) {
    const color = business.primary_color || '#4f46e5';

    return (
        <div className="flex min-h-screen flex-col bg-white">
            <Head title={business.name} />

            <header className="border-b border-slate-100">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-4 sm:px-6">
                    <span className="text-xl font-bold" style={{ color }}>
                        {business.name}
                    </span>
                </div>
            </header>

            <main className="flex-1">
                <section className="mx-auto max-w-5xl px-4 py-20 text-center sm:px-6">
                    <p className="text-sm font-semibold tracking-wide uppercase" style={{ color }}>
                        {business.business_type}
                    </p>
                    <h1 className="mt-3 text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
                        Welcome to {business.name}
                    </h1>
                    <p className="mx-auto mt-4 max-w-xl text-lg text-slate-600">
                        Our website is being set up. Please check back soon.
                    </p>
                </section>

                <section className="mx-auto max-w-5xl px-4 pb-16 sm:px-6">
                    <p className="text-center text-xs text-slate-400">
                        Planned sections: {sections.map(humanize).join(' · ')}
                    </p>
                </section>
            </main>

            <footer className="border-t border-slate-100 py-6 text-center text-sm text-slate-500">
                © {new Date().getFullYear()} {business.name}
            </footer>
        </div>
    );
}

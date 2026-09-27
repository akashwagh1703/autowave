import { usePage } from '@inertiajs/react';

export default function PublicLayout({ children }) {
    const { app } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-gradient-to-b from-brand-50 to-white">
            <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-5 sm:px-6">
                <span className="text-xl font-bold text-brand-700">{app.name}</span>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-slate-200 py-6 text-center text-sm text-slate-500">
                © {new Date().getFullYear()} {app.name}
            </footer>
        </div>
    );
}

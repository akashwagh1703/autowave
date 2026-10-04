import { Link, usePage } from '@inertiajs/react';
import Button from '@mui/material/Button';

const footerLinks = [
    { href: '/pricing', label: 'Pricing' },
    { href: '/contact', label: 'Contact' },
    { href: '/terms', label: 'Terms' },
    { href: '/privacy', label: 'Privacy' },
    { href: '/refunds', label: 'Refunds and cancellation' },
];

/** The marketing site (autowave.co.in): header with sign-in links, footer with the policies. */
export default function PublicLayout({ children }) {
    const { app, appUrl } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-gradient-to-b from-brand-50 to-white">
            <header className="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-4 py-5 sm:px-6">
                <Link href="/" className="text-xl font-bold text-brand-700">
                    {app.name}
                </Link>
                <nav className="flex items-center gap-1 sm:gap-2">
                    <Button component={Link} href="/pricing" color="inherit" size="small">
                        Pricing
                    </Button>
                    {appUrl ? (
                        <Button href={`${appUrl}/login`} variant="outlined" size="small">
                            Log in
                        </Button>
                    ) : null}
                </nav>
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-slate-200 py-6 text-sm text-slate-500">
                <div className="mx-auto flex max-w-5xl flex-col items-center gap-3 px-4 sm:flex-row sm:justify-between sm:px-6">
                    <span>
                        © {new Date().getFullYear()} {app.name}
                    </span>
                    <nav className="flex flex-wrap justify-center gap-x-4 gap-y-1">
                        {footerLinks.map((link) => (
                            <Link key={link.href} href={link.href} className="hover:text-slate-700 hover:underline">
                                {link.label}
                            </Link>
                        ))}
                    </nav>
                </div>
            </footer>
        </div>
    );
}

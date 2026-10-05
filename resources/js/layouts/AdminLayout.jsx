import { Head, Link, router, usePage } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import FlashMessages from '@/components/FlashMessages';

const navigation = [
    { label: 'Dashboard', href: '/' },
    { label: 'Tenants', href: '/tenants' },
    { label: 'Demo requests', href: '/demo-requests' },
    { label: 'Payments', href: '/billing/payments' },
    { label: 'Plans', href: '/billing/plans' },
    { label: 'Coupons', href: '/billing/coupons' },
    { label: 'AI usage', href: '/ai-usage' },
    { label: 'Settings', href: '/settings' },
];

export default function AdminLayout({ title, children }) {
    const { app, auth } = usePage().props;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : '';

    return (
        <div className="min-h-screen bg-slate-50">
            <Head title={title} />

            <header className="bg-slate-900 text-white print:hidden">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
                    <Link href="/" className="flex items-center gap-2 text-lg font-bold">
                        {app.name}
                        <Chip label="Super Admin" size="small" color="secondary" />
                    </Link>

                    <nav className="order-last flex w-full gap-1 sm:order-none sm:w-auto">
                        {navigation.map((item) => (
                            <Button
                                key={item.href}
                                component={Link}
                                href={item.href}
                                size="small"
                                sx={{ color: currentPath === item.href ? 'secondary.light' : 'grey.300' }}
                            >
                                {item.label}
                            </Button>
                        ))}
                    </nav>

                    <div className="ml-auto flex items-center gap-3">
                        <span className="hidden text-sm text-slate-300 sm:inline">{auth.user?.email}</span>
                        <Button size="small" variant="outlined" color="inherit" onClick={() => router.post('/logout')}>
                            Log out
                        </Button>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6">{children}</main>

            <FlashMessages />
        </div>
    );
}

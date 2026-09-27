import { Head, usePage } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FlashMessages from '@/components/FlashMessages';

export default function AuthLayout({ title, subtitle, badge, children }) {
    const { app } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-brand-50 to-white px-4 py-10">
            <Head title={title} />

            <div className="mb-6 text-center">
                <span className="text-2xl font-bold text-brand-700">{app.name}</span>
                {badge ? <p className="mt-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">{badge}</p> : null}
            </div>

            <Card variant="outlined" className="w-full max-w-md">
                <CardContent className="p-6 sm:p-8">
                    <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
                    {subtitle ? <p className="mt-1 text-sm text-slate-600">{subtitle}</p> : null}
                    <div className="mt-6">{children}</div>
                </CardContent>
            </Card>

            <FlashMessages />
        </div>
    );
}

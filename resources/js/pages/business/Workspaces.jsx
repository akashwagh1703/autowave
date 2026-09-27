import { Head, router, usePage } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardActionArea from '@mui/material/CardActionArea';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import StorefrontIcon from '@mui/icons-material/Storefront';
import EmptyState from '@/components/EmptyState';
import FlashMessages from '@/components/FlashMessages';

export default function Workspaces({ workspaces }) {
    const { app, auth } = usePage().props;

    return (
        <div className="min-h-screen bg-slate-50">
            <Head title="Your businesses" />

            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-3xl items-center justify-between px-4 py-3 sm:px-6">
                    <span className="text-lg font-bold text-brand-700">{app.name}</span>
                    <Button size="small" color="inherit" onClick={() => router.post('/logout')}>
                        Log out
                    </Button>
                </div>
            </header>

            <main className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
                <h1 className="text-2xl font-bold tracking-tight text-slate-900">Your businesses</h1>
                <p className="mt-1 text-sm text-slate-600">Signed in as {auth.user?.email}</p>

                <div className="mt-6 space-y-3">
                    {workspaces.length === 0 ? (
                        <EmptyState
                            icon={StorefrontIcon}
                            title="No business workspace yet"
                            description="You're not a member of any business. Ask a business owner to invite you. Self-service business setup is coming soon."
                        />
                    ) : (
                        workspaces.map((workspace) => (
                            <Card key={workspace.id} variant="outlined">
                                <CardActionArea onClick={() => router.post(`/workspaces/${workspace.id}/switch`)}>
                                    <CardContent className="flex items-center justify-between gap-4">
                                        <div>
                                            <p className="font-semibold text-slate-900">{workspace.name}</p>
                                            <p className="text-sm text-slate-500">{workspace.business_type}</p>
                                        </div>
                                        {workspace.is_current ? <Chip label="Current" color="primary" size="small" /> : null}
                                    </CardContent>
                                </CardActionArea>
                            </Card>
                        ))
                    )}
                </div>
            </main>

            <FlashMessages />
        </div>
    );
}

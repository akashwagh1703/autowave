import { Link, router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import CheckIcon from '@mui/icons-material/Check';
import SoupKitchenIcon from '@mui/icons-material/SoupKitchenOutlined';
import { useEffect, useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import useTenant from '@/hooks/useTenant';
import { formatTime } from '@/utils/booking';

function minutesSince(iso, offset) {
    return iso ? Math.max(0, Math.floor((Date.now() + offset - new Date(iso).getTime()) / 60000)) : 0;
}

function Ticket({ ticket, canUpdate, offset }) {
    const { timezone } = useTenant();
    const [processing, setProcessing] = useState(false);
    const waiting = minutesSince(ticket.queued_since, offset);
    const late = waiting >= 20;

    const ready = () =>
        router.post(`/kitchen/${ticket.id}/ready`, {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });

    return (
        <Card variant="outlined" className={late ? 'border-red-300' : ''}>
            <div className={`flex items-center justify-between gap-2 px-4 py-2 ${late ? 'bg-red-50' : 'bg-slate-50'}`}>
                <div>
                    <p className="text-lg font-bold text-slate-900">{ticket.table ? `Table ${ticket.table}` : ticket.fulfilment_label}</p>
                    <p className="text-xs text-slate-500">
                        <Link href={`/orders/${ticket.id}`} className="hover:text-brand-700">
                            {ticket.reference}
                        </Link>
                        {ticket.customer ? ` · ${ticket.customer}` : ''}
                    </p>
                </div>
                <span className={`text-sm font-semibold ${late ? 'text-red-700' : 'text-slate-600'}`} title={`Since ${formatTime(ticket.queued_since, timezone)}`}>
                    {waiting} min
                </span>
            </div>
            <CardContent className="space-y-2">
                <ul className="space-y-1.5">
                    {ticket.items.map((item) => (
                        <li key={item.id} className="text-base text-slate-900">
                            <span className="font-semibold">{item.quantity} ×</span> {item.name}
                            {item.added_later ? <Chip label="Added" size="small" color="warning" variant="outlined" className="ml-2" /> : null}
                            {item.notes ? <p className="text-sm text-amber-800">↳ {item.notes}</p> : null}
                        </li>
                    ))}
                </ul>
                {ticket.notes ? <p className="rounded bg-amber-50 px-2 py-1 text-sm text-amber-900">{ticket.notes}</p> : null}
                {canUpdate ? (
                    <Button fullWidth variant="contained" color="success" startIcon={<CheckIcon />} disabled={processing} onClick={ready}>
                        Ready
                    </Button>
                ) : null}
            </CardContent>
        </Card>
    );
}

export default function Index({ tickets, refreshSeconds, serverTime }) {
    const { can } = useTenant();
    const [offset] = useState(() => new Date(serverTime).getTime() - Date.now());
    const [, tick] = useState(0);

    useEffect(() => {
        const reload = setInterval(() => router.reload({ only: ['tickets', 'serverTime'] }), refreshSeconds * 1000);
        const clock = setInterval(() => tick((value) => value + 1), 30000);

        return () => {
            clearInterval(reload);
            clearInterval(clock);
        };
    }, [refreshSeconds]);

    return (
        <AppLayout title="Kitchen">
            <PageHeader
                title="Kitchen"
                description={`${tickets.length} ticket${tickets.length === 1 ? '' : 's'} to prepare · refreshes every ${refreshSeconds} seconds`}
                actions={
                    <Button component={Link} href="/orders" color="inherit">
                        Orders
                    </Button>
                }
            />

            {tickets.length === 0 ? (
                <EmptyState icon={SoupKitchenIcon} title="Nothing to prepare" description="Confirmed orders appear here with the items to cook, oldest first." />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {tickets.map((ticket) => (
                        <Ticket key={ticket.id} ticket={ticket} canUpdate={can('orders.update')} offset={offset} />
                    ))}
                </div>
            )}
        </AppLayout>
    );
}

import { Link, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import EditIcon from '@mui/icons-material/Edit';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import Timeline from '@/modules/crm/Timeline';
import ReservationActions from '@/modules/food/ReservationActions';
import ReservationDialog from '@/modules/food/ReservationDialog';
import ReservationStatusChip from '@/modules/food/ReservationStatusChip';
import useTenant from '@/hooks/useTenant';
import { formatDuration, formatTime, localDate } from '@/utils/booking';
import { formatDateTime } from '@/utils/format';

function Detail({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-slate-500">{label}</dt>
            <dd className="text-sm text-slate-900">{children || '—'}</dd>
        </div>
    );
}

export default function Show({ reservation, tables, durationOptions, activities }) {
    const { can, hasEngine, timezone } = useTenant();
    const { errors } = usePage().props;
    const [editing, setEditing] = useState(false);
    const canManage = can('reservations.manage');
    const date = localDate(reservation.reserved_at, timezone);
    const tableOptions = reservation.table && !tables.some((table) => table.id === reservation.table.id) ? [...tables, reservation.table] : tables;

    return (
        <AppLayout title={`Reservation · ${reservation.customer?.name ?? 'Guest'}`}>
            <Button component={Link} href={`/reservations?date=${date}`} startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Reservations
            </Button>
            <PageHeader
                title={`${reservation.customer?.name ?? 'Guest'} · ${reservation.party_size} guest${reservation.party_size === 1 ? '' : 's'}`}
                description={`${formatDateTime(reservation.reserved_at, timezone, { weekday: 'long', day: 'numeric', month: 'short' })}, ${formatTime(reservation.reserved_at, timezone)}–${formatTime(reservation.ends_at, timezone)}`}
                actions={
                    canManage && reservation.holds_table ? (
                        <Button color="inherit" startIcon={<EditIcon />} onClick={() => setEditing(true)}>
                            Change
                        </Button>
                    ) : null
                }
            />

            {errors.status ? (
                <Alert severity="error" className="mb-4">
                    {errors.status}
                </Alert>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card variant="outlined">
                        <CardContent>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <ReservationStatusChip reservation={reservation} />
                                {canManage ? <ReservationActions reservation={reservation} size="medium" /> : null}
                            </div>
                            <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <Detail label="Table">{reservation.table ? `${reservation.table.name} (${reservation.table.seats} seats)` : 'Not assigned'}</Detail>
                                <Detail label="Duration">{formatDuration(reservation.duration_minutes)}</Detail>
                                <Detail label="Booked via">{reservation.source_label}</Detail>
                                <Detail label="Booked">{formatDateTime(reservation.created_at, timezone)}</Detail>
                                {reservation.creator ? <Detail label="By">{reservation.creator.name}</Detail> : null}
                                {reservation.cancellation_reason ? <Detail label="Cancellation reason">{reservation.cancellation_reason}</Detail> : null}
                            </dl>
                            {reservation.notes ? <p className="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{reservation.notes}</p> : null}
                            {reservation.status === 'seated' && reservation.table && hasEngine('commerce') && can('orders.create') ? (
                                <Button component={Link} href={`/orders/create?table=${reservation.table.id}`} variant="contained" className="mt-4">
                                    Start dine-in order
                                </Button>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="mb-4 font-semibold text-slate-900">Activity</h2>
                            <Timeline activities={activities} timezone={timezone} />
                        </CardContent>
                    </Card>
                </div>

                <Card variant="outlined" className="h-fit">
                    <CardContent className="space-y-1 text-sm">
                        <h2 className="font-semibold text-slate-900">Guest</h2>
                        <p className="text-slate-900">{reservation.customer?.name}</p>
                        {reservation.customer?.phone ? (
                            <a href={`tel:${reservation.customer.phone}`} className="block text-brand-700 hover:underline">
                                {reservation.customer.phone}
                            </a>
                        ) : null}
                        {reservation.customer && !reservation.customer.deleted && can('customers.view') ? (
                            <Link href={`/customers/${reservation.customer.id}`} className="block text-brand-700 hover:underline">
                                Customer profile
                            </Link>
                        ) : null}
                    </CardContent>
                </Card>
            </div>

            {editing ? (
                <ReservationDialog
                    reservation={reservation}
                    date={date}
                    tables={tableOptions}
                    durationOptions={durationOptions}
                    defaultDuration={reservation.duration_minutes}
                    open
                    onClose={() => setEditing(false)}
                />
            ) : null}
        </AppLayout>
    );
}

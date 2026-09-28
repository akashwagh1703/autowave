import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import IconButton from '@mui/material/IconButton';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import EventSeatIcon from '@mui/icons-material/EventSeatOutlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import ReservationActions from '@/modules/food/ReservationActions';
import ReservationDialog from '@/modules/food/ReservationDialog';
import ReservationStatusChip from '@/modules/food/ReservationStatusChip';
import useTenant from '@/hooks/useTenant';
import { addDays, formatDay, formatTime } from '@/utils/booking';

const views = [
    { value: 'live', label: 'Bookings' },
    { value: 'cancelled', label: 'Cancelled & no-shows' },
    { value: 'all', label: 'All' },
];

export default function Index({ reservations, filters, today, counts, tables, defaults, durationOptions }) {
    const { can, timezone } = useTenant();
    const { errors } = usePage().props;
    const canManage = can('reservations.manage');
    const [booking, setBooking] = useState(false);

    const visit = (changes) => router.get('/reservations', { ...filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });
    const guests = reservations.filter((reservation) => reservation.holds_table).reduce((sum, reservation) => sum + reservation.party_size, 0);

    return (
        <AppLayout title="Reservations">
            <PageHeader
                title="Reservations"
                description={`${counts.guests} guest${counts.guests === 1 ? '' : 's'} expected ${filters.date === today ? 'today' : 'on this day'}${counts.pending ? ` · ${counts.pending} waiting for confirmation` : ''}`}
                actions={
                    <>
                        <Button component={Link} href="/tables" color="inherit">
                            Tables
                        </Button>
                        {canManage ? (
                            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setBooking(true)}>
                                Book table
                            </Button>
                        ) : null}
                    </>
                }
            />

            {errors.status ? (
                <Alert severity="error" className="mb-4">
                    {errors.status}
                </Alert>
            ) : null}

            <Card variant="outlined">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <div className="flex items-center gap-1">
                        <IconButton size="small" aria-label="Previous day" onClick={() => visit({ date: addDays(filters.date, -1) })}>
                            <ChevronLeftIcon />
                        </IconButton>
                        <TextField
                            type="date"
                            size="small"
                            value={filters.date}
                            onChange={(event) => event.target.value && visit({ date: event.target.value })}
                            slotProps={{ htmlInput: { 'aria-label': 'Date' } }}
                        />
                        <IconButton size="small" aria-label="Next day" onClick={() => visit({ date: addDays(filters.date, 1) })}>
                            <ChevronRightIcon />
                        </IconButton>
                        {filters.date !== today ? (
                            <Button size="small" onClick={() => visit({ date: today })}>
                                Today
                            </Button>
                        ) : null}
                    </div>
                    <p className="text-sm font-medium text-slate-700">{formatDay(filters.date)}</p>
                </div>
                <Tabs value={filters.view} onChange={(_, view) => visit({ view })} variant="scrollable" className="border-b border-slate-200 px-2">
                    {views.map((view) => (
                        <Tab key={view.value} value={view.value} label={view.label} />
                    ))}
                </Tabs>

                {reservations.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={EventSeatIcon}
                            title="No reservations"
                            description={filters.view === 'cancelled' ? 'Nothing cancelled on this day.' : 'Table bookings for this day will appear here.'}
                            action={
                                canManage && filters.view !== 'cancelled' ? (
                                    <Button variant="contained" startIcon={<AddIcon />} onClick={() => setBooking(true)}>
                                        Book table
                                    </Button>
                                ) : null
                            }
                        />
                    </div>
                ) : (
                    <>
                        <ul className="divide-y divide-slate-100">
                            {reservations.map((reservation) => (
                                <li key={reservation.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                                    <div className="w-24">
                                        <Link href={`/reservations/${reservation.id}`} className="text-lg font-semibold text-slate-900 hover:text-brand-700">
                                            {formatTime(reservation.reserved_at, timezone)}
                                        </Link>
                                        <p className="text-xs text-slate-500">until {formatTime(reservation.ends_at, timezone)}</p>
                                    </div>
                                    <div className="min-w-40 flex-1">
                                        <Link href={`/reservations/${reservation.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                            {reservation.customer?.name ?? 'Guest'}
                                        </Link>
                                        <p className="text-xs text-slate-500">
                                            {reservation.party_size} guest{reservation.party_size === 1 ? '' : 's'}
                                            {reservation.customer?.phone ? ` · ${reservation.customer.phone}` : ''}
                                            {reservation.source === 'website' ? ' · online' : ''}
                                        </p>
                                        {reservation.notes ? <p className="line-clamp-1 text-xs text-slate-600">{reservation.notes}</p> : null}
                                    </div>
                                    <div className="w-28 text-sm">
                                        {reservation.table ? <span className="text-slate-900">{reservation.table.name}</span> : <span className="text-amber-700">No table</span>}
                                    </div>
                                    <ReservationStatusChip reservation={reservation} />
                                    {canManage && reservation.transitions.length ? (
                                        <div className="w-full sm:w-auto">
                                            <ReservationActions reservation={reservation} />
                                        </div>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
                            {reservations.length} reservation{reservations.length === 1 ? '' : 's'} · {guests} guests holding tables
                        </p>
                    </>
                )}
            </Card>

            {booking ? (
                <ReservationDialog
                    date={filters.date}
                    tables={tables}
                    durationOptions={durationOptions}
                    defaultDuration={defaults.duration_minutes}
                    open
                    onClose={() => setBooking(false)}
                />
            ) : null}
        </AppLayout>
    );
}

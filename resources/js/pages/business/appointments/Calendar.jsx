import { Link, router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import ViewListIcon from '@mui/icons-material/ViewList';
import { useMemo, useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import useTenant from '@/hooks/useTenant';
import { STATUS_STYLES, addDays, formatDay, formatTime, localDate, minutesIntoDay } from '@/utils/booking';

const HOUR_HEIGHT = 64;
const SNAP_MINUTES = 15;

// [start, end) of a period in minutes since midnight of `date`, clipped to that day.
function span(startIso, endIso, timezone, date) {
    const start = localDate(startIso, timezone) < date ? 0 : minutesIntoDay(startIso, timezone);
    const end = localDate(endIso, timezone) > date ? 24 * 60 : minutesIntoDay(endIso, timezone);

    return [start, Math.max(end, start)];
}

function pad(minutes) {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

export default function Calendar({ date, today, filter, myResourceId, resources, appointments, summary, allResources }) {
    const { timezone, can, resourceLabel } = useTenant();
    const [showCancelled, setShowCancelled] = useState(false);
    const canBook = can('appointments.create') && date >= today;

    const visit = (params) =>
        router.get('/appointments', Object.fromEntries(Object.entries({ date, resource: filter, ...params }).filter(([, value]) => value)), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const visible = appointments.filter((appointment) => showCancelled || !['cancelled', 'no_show'].includes(appointment.status));

    const [startHour, endHour] = useMemo(() => {
        const minutes = [];

        resources.forEach((resource) => resource.windows.forEach((window) => minutes.push(...span(window.starts_at, window.ends_at, timezone, date))));
        visible.forEach((appointment) => minutes.push(...span(appointment.starts_at, appointment.ends_at, timezone, date)));

        if (minutes.length === 0) {
            return [9, 18];
        }

        return [Math.max(0, Math.floor(Math.min(...minutes) / 60)), Math.min(24, Math.ceil(Math.max(...minutes) / 60))];
    }, [resources, visible, timezone, date]);

    const top = (minutes) => ((minutes - startHour * 60) / 60) * HOUR_HEIGHT;
    const height = (hours) => hours * HOUR_HEIGHT;
    const nowMinutes = date === today ? minutesIntoDay(new Date().toISOString(), timezone) : null;

    const bookAt = (event, resource) => {
        if (!canBook || !resource.is_active || resource.deleted) {
            return;
        }

        const offset = event.clientY - event.currentTarget.getBoundingClientRect().top;
        const minutes = Math.floor((startHour * 60 + (offset / HOUR_HEIGHT) * 60) / SNAP_MINUTES) * SNAP_MINUTES;
        router.visit(`/appointments/create?resource=${resource.id}&date=${date}&time=${pad(minutes)}`);
    };

    return (
        <AppLayout title="Appointments">
            <PageHeader
                title="Appointments"
                description={`${formatDay(date)} · times in ${timezone}`}
                actions={
                    <>
                        <Button component={Link} href="/appointments/list" color="inherit" startIcon={<ViewListIcon />}>
                            List
                        </Button>
                        {can('appointments.create') ? (
                            <Button component={Link} href={`/appointments/create?date=${date >= today ? date : today}`} variant="contained" startIcon={<AddIcon />}>
                                Book appointment
                            </Button>
                        ) : null}
                    </>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Button variant={date === today ? 'contained' : 'outlined'} size="small" onClick={() => visit({ date: today })}>
                    Today
                </Button>
                <IconButton aria-label="Previous day" onClick={() => visit({ date: addDays(date, -1) })}>
                    <ChevronLeftIcon />
                </IconButton>
                <IconButton aria-label="Next day" onClick={() => visit({ date: addDays(date, 1) })}>
                    <ChevronRightIcon />
                </IconButton>
                <TextField
                    type="date"
                    size="small"
                    value={date}
                    onChange={(event) => event.target.value && visit({ date: event.target.value })}
                    slotProps={{ htmlInput: { 'aria-label': 'Go to date' } }}
                    className="w-44"
                />
                <TextField
                    select
                    size="small"
                    label={resourceLabel.singular}
                    value={filter ?? ''}
                    onChange={(event) => visit({ resource: event.target.value || null })}
                    slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
                    className="min-w-44"
                >
                    <MenuItem value="">All {resourceLabel.plural.toLowerCase()}</MenuItem>
                    {myResourceId ? <MenuItem value="mine">My schedule</MenuItem> : null}
                    {allResources.map((resource) => (
                        <MenuItem key={resource.id} value={String(resource.id)}>
                            {resource.name}
                        </MenuItem>
                    ))}
                </TextField>
                <FormControlLabel
                    control={<Switch size="small" checked={showCancelled} onChange={(event) => setShowCancelled(event.target.checked)} />}
                    label={<span className="text-sm">Show cancelled</span>}
                    className="ml-1"
                />
                <div className="ml-auto flex flex-wrap gap-3 text-sm text-slate-600">
                    <span>
                        <strong className="text-slate-900">{summary.booked}</strong> booked
                    </span>
                    {summary.pending ? (
                        <span>
                            <strong className="text-amber-700">{summary.pending}</strong> pending
                        </span>
                    ) : null}
                    <span>
                        <strong className="text-slate-900">{summary.completed}</strong> completed
                    </span>
                    {summary.cancelled ? <span>{summary.cancelled} cancelled / no-show</span> : null}
                </div>
            </div>

            {resources.length === 0 ? (
                <EmptyState
                    icon={EventAvailableIcon}
                    title={`No ${resourceLabel.plural.toLowerCase()} to book`}
                    description={`Add a ${resourceLabel.singular.toLowerCase()} with working hours to start taking appointments.`}
                    action={
                        can('resources.manage') ? (
                            <Button component={Link} href="/resources/create" variant="contained" startIcon={<AddIcon />}>
                                Add {resourceLabel.singular.toLowerCase()}
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <Card variant="outlined" className="overflow-x-auto">
                    <div className="flex min-w-fit">
                        <div className="sticky left-0 z-20 w-14 shrink-0 border-r border-slate-200 bg-white">
                            <div className="h-12 border-b border-slate-200" />
                            <div className="relative" style={{ height: height(endHour - startHour) }}>
                                {Array.from({ length: endHour - startHour }, (_, index) => (
                                    <span key={index} className="absolute right-2 -translate-y-2 text-xs text-slate-400" style={{ top: index * HOUR_HEIGHT }}>
                                        {index === 0 ? '' : pad((startHour + index) * 60)}
                                    </span>
                                ))}
                            </div>
                        </div>

                        {resources.map((resource) => {
                            const own = visible.filter((appointment) => appointment.booking_resource_id === resource.id);
                            const bookable = canBook && resource.is_active && !resource.deleted;

                            return (
                                <div key={resource.id} className="w-48 min-w-48 flex-1 border-r border-slate-200 last:border-r-0">
                                    <Link
                                        href={`/resources/${resource.id}`}
                                        className="flex h-12 items-center gap-2 border-b border-slate-200 px-3 hover:bg-slate-50"
                                    >
                                        <span className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: resource.color }} />
                                        <span className="truncate text-sm font-medium text-slate-900">{resource.name}</span>
                                        {!resource.is_active || resource.deleted ? <span className="text-xs text-slate-400">(inactive)</span> : null}
                                    </Link>
                                    <div
                                        className={`relative bg-slate-100 ${bookable ? 'cursor-pointer' : ''}`}
                                        style={{ height: height(endHour - startHour) }}
                                        onClick={(event) => bookAt(event, resource)}
                                        role={bookable ? 'button' : undefined}
                                        aria-label={bookable ? `Book ${resource.name} on ${formatDay(date)}` : undefined}
                                    >
                                        {Array.from({ length: endHour - startHour }, (_, index) => (
                                            <div key={index} className="pointer-events-none absolute inset-x-0 z-[1] border-t border-slate-200" style={{ top: index * HOUR_HEIGHT }} />
                                        ))}
                                        {resource.windows.map((window) => {
                                            const [start, end] = span(window.starts_at, window.ends_at, timezone, date);

                                            return <div key={window.starts_at} className="absolute inset-x-0 bg-white" style={{ top: top(start), height: top(end) - top(start) }} />;
                                        })}
                                        {resource.time_off.map((period) => {
                                            const [start, end] = span(period.starts_at, period.ends_at, timezone, date);

                                            return (
                                                <div
                                                    key={period.id}
                                                    className="absolute inset-x-0 z-[2] bg-[repeating-linear-gradient(45deg,#f1f5f9,#f1f5f9_6px,#e2e8f0_6px,#e2e8f0_12px)] px-2 py-1 text-xs text-slate-500"
                                                    style={{ top: top(start), height: Math.max(top(end) - top(start), 18) }}
                                                    onClick={(event) => event.stopPropagation()}
                                                >
                                                    Time off{period.reason ? ` · ${period.reason}` : ''}
                                                </div>
                                            );
                                        })}
                                        {own.map((appointment) => {
                                            const [start, end] = span(appointment.starts_at, appointment.ends_at, timezone, date);

                                            return (
                                                <Link
                                                    key={appointment.id}
                                                    href={`/appointments/${appointment.id}`}
                                                    onClick={(event) => event.stopPropagation()}
                                                    className={`absolute inset-x-1 z-[3] overflow-hidden rounded-md border-l-4 px-2 py-1 text-xs shadow-sm hover:shadow ${STATUS_STYLES[appointment.status]?.block ?? ''}`}
                                                    style={{ top: top(start) + 1, height: Math.max(top(end) - top(start) - 2, 20) }}
                                                    title={`${appointment.customer?.name} · ${appointment.status_label}`}
                                                >
                                                    <p className="font-semibold">
                                                        {formatTime(appointment.starts_at, timezone)} {appointment.customer?.name}
                                                    </p>
                                                    {appointment.service ? <p className="truncate">{appointment.service.name}</p> : null}
                                                    {appointment.status === 'pending' ? <p className="font-medium">Pending</p> : null}
                                                </Link>
                                            );
                                        })}
                                        {nowMinutes !== null && nowMinutes >= startHour * 60 && nowMinutes <= endHour * 60 ? (
                                            <div className="pointer-events-none absolute inset-x-0 z-[4] border-t-2 border-red-500" style={{ top: top(nowMinutes) }} />
                                        ) : null}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            )}

            <p className="mt-3 text-xs text-slate-500">
                White is open, grey is closed, striped is time off.{canBook ? ' Click an open time to book it.' : ''}
            </p>
        </AppLayout>
    );
}

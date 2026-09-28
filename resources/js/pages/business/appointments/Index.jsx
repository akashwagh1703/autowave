import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Checkbox from '@mui/material/Checkbox';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import CalendarMonthIcon from '@mui/icons-material/CalendarMonth';
import EventBusyIcon from '@mui/icons-material/EventBusy';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import ConfirmDialog from '@/components/ConfirmDialog';
import AppointmentStatusChip from '@/modules/booking/AppointmentStatusChip';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatMoney } from '@/utils/format';
import { formatDuration, formatTime } from '@/utils/booking';

const ranges = [
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'today', label: 'Today' },
    { value: 'past', label: 'Past' },
    { value: 'all', label: 'All' },
];

function BulkBar({ selected, can, onDone }) {
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const run = (action, extra = {}) =>
        router.post('/appointments/bulk', { ids: selected, action, ...extra }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmCancel(false);
            },
            onSuccess: onDone,
        });

    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-brand-50 px-4 py-2">
            <span className="text-sm font-medium text-brand-700">{selected.length} selected</span>
            {can('appointments.update') ? (
                <>
                    <Button size="small" disabled={processing} onClick={() => run('confirmed')}>
                        Confirm
                    </Button>
                    <Button size="small" disabled={processing} onClick={() => run('completed')}>
                        Mark completed
                    </Button>
                    <Button size="small" disabled={processing} onClick={() => run('no_show')}>
                        Mark no-show
                    </Button>
                </>
            ) : null}
            {can('appointments.cancel') ? (
                <Button size="small" color="error" disabled={processing} onClick={() => setConfirmCancel(true)}>
                    Cancel
                </Button>
            ) : null}
            <span className="text-xs text-slate-500">Appointments that don’t allow the change are skipped.</span>
            <ConfirmDialog
                open={confirmCancel}
                title={`Cancel ${selected.length} appointment${selected.length === 1 ? '' : 's'}?`}
                description="The time becomes free for new bookings."
                confirmLabel="Cancel appointments"
                destructive
                processing={processing}
                onConfirm={() => run('cancelled', { reason })}
                onClose={() => setConfirmCancel(false)}
            >
                <TextField
                    fullWidth
                    size="small"
                    label="Reason (optional)"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    slotProps={{ htmlInput: { maxLength: 255 } }}
                    className="mt-3"
                />
            </ConfirmDialog>
        </div>
    );
}

export default function Index({ appointments, filters: initialFilters, counts, statuses, resources, services }) {
    const { timezone, currency, can, hasEngine, resourceLabel } = useTenant();
    const { errors } = usePage().props;
    const { filters, apply, applyDebounced, loading } = useFilters('/appointments/list', initialFilters);
    const [selected, setSelected] = useState([]);
    const canBulk = can('appointments.update') || can('appointments.cancel');
    const filtering = Boolean(filters.search || filters.status || filters.resource || filters.service);

    const pageIds = appointments.data.map((appointment) => appointment.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const toggle = (id) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    const change = (changes) => {
        setSelected([]);
        apply(changes);
    };

    return (
        <AppLayout title="Appointments">
            <PageHeader
                title="Appointments"
                description={`${counts.today} booked today${counts.pending ? ` · ${counts.pending} waiting for confirmation` : ''}`}
                actions={
                    <>
                        <Button component={Link} href="/appointments" color="inherit" startIcon={<CalendarMonthIcon />}>
                            Calendar
                        </Button>
                        {can('appointments.create') ? (
                            <Button component={Link} href="/appointments/create" variant="contained" startIcon={<AddIcon />}>
                                Book appointment
                            </Button>
                        ) : null}
                    </>
                }
            />

            <Card variant="outlined">
                <Tabs value={filters.range} onChange={(_, range) => change({ range })} variant="scrollable" className="border-b border-slate-200 px-2">
                    {ranges.map((range) => (
                        <Tab key={range.value} value={range.value} label={range.label} />
                    ))}
                </Tabs>

                <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <SearchField
                        value={filters.search}
                        onChange={(search) => applyDebounced({ search })}
                        placeholder="Search customer name or phone"
                        loading={loading}
                        className="min-w-64 flex-1"
                    />
                    <TextField select size="small" label="Status" value={filters.status ?? ''} onChange={(event) => change({ status: event.target.value || null })} className="min-w-36">
                        <MenuItem value="">Any status</MenuItem>
                        {statuses.map((status) => (
                            <MenuItem key={status.value} value={status.value}>
                                {status.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField
                        select
                        size="small"
                        label={resourceLabel.singular}
                        value={filters.resource ?? ''}
                        onChange={(event) => change({ resource: event.target.value || null })}
                        className="min-w-40"
                    >
                        <MenuItem value="">Anyone</MenuItem>
                        {resources.map((resource) => (
                            <MenuItem key={resource.id} value={resource.id}>
                                {resource.name}
                            </MenuItem>
                        ))}
                    </TextField>
                    {hasEngine('service') ? (
                        <TextField select size="small" label="Service" value={filters.service ?? ''} onChange={(event) => change({ service: event.target.value || null })} className="min-w-40">
                            <MenuItem value="">Any service</MenuItem>
                            {services.map((service) => (
                                <MenuItem key={service.id} value={service.id}>
                                    {service.name}
                                </MenuItem>
                            ))}
                        </TextField>
                    ) : null}
                </div>

                {loading ? <LinearProgress /> : <div className="h-1" />}

                {errors.ids || errors.action || errors.status ? (
                    <Alert severity="error" className="m-4">
                        {errors.ids ?? errors.action ?? errors.status}
                    </Alert>
                ) : null}

                {selected.length > 0 && canBulk ? <BulkBar selected={selected} can={can} onDone={() => setSelected([])} /> : null}

                {appointments.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={EventBusyIcon}
                            title={filtering ? 'No appointments match' : `No ${filters.range === 'all' ? '' : `${filters.range} `}appointments`}
                            description={filtering ? 'Try a different search or filter.' : 'Bookings you make will appear here.'}
                            action={
                                !filtering && can('appointments.create') ? (
                                    <Button component={Link} href="/appointments/create" variant="contained" startIcon={<AddIcon />}>
                                        Book appointment
                                    </Button>
                                ) : null
                            }
                        />
                    </div>
                ) : (
                    <TableContainer>
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    {canBulk ? (
                                        <TableCell padding="checkbox">
                                            <Checkbox
                                                checked={allSelected}
                                                indeterminate={!allSelected && selected.length > 0}
                                                onChange={() => setSelected(allSelected ? [] : pageIds)}
                                                slotProps={{ input: { 'aria-label': 'Select all appointments on this page' } }}
                                            />
                                        </TableCell>
                                    ) : null}
                                    <TableCell>When</TableCell>
                                    <TableCell>Customer</TableCell>
                                    <TableCell className="hidden md:table-cell">{resourceLabel.singular}</TableCell>
                                    <TableCell className="hidden md:table-cell">Service</TableCell>
                                    <TableCell align="right" className="hidden lg:table-cell">
                                        Price
                                    </TableCell>
                                    <TableCell>Status</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {appointments.data.map((appointment) => (
                                    <TableRow key={appointment.id} hover selected={selected.includes(appointment.id)}>
                                        {canBulk ? (
                                            <TableCell padding="checkbox">
                                                <Checkbox
                                                    checked={selected.includes(appointment.id)}
                                                    onChange={() => toggle(appointment.id)}
                                                    slotProps={{ input: { 'aria-label': `Select appointment for ${appointment.customer?.name}` } }}
                                                />
                                            </TableCell>
                                        ) : null}
                                        <TableCell>
                                            <Link href={`/appointments/${appointment.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                                {formatDateTime(appointment.starts_at, timezone, { weekday: 'short', day: 'numeric', month: 'short' })}
                                            </Link>
                                            <p className="text-xs text-slate-500">
                                                {formatTime(appointment.starts_at, timezone)}–{formatTime(appointment.ends_at, timezone)} · {formatDuration(appointment.duration_minutes)}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            <span className="text-slate-900">{appointment.customer?.name}</span>
                                            <p className="text-xs text-slate-500">{appointment.customer?.phone ?? ''}</p>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            <span className="flex items-center gap-2">
                                                <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: appointment.resource?.color }} />
                                                {appointment.resource?.name}
                                            </span>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">{appointment.service?.name ?? <span className="text-slate-400">—</span>}</TableCell>
                                        <TableCell align="right" className="hidden lg:table-cell">
                                            {formatMoney(appointment.price, currency)}
                                        </TableCell>
                                        <TableCell>
                                            <AppointmentStatusChip appointment={appointment} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={appointments.meta} noun="appointments" />
        </AppLayout>
    );
}

import { Head, useForm, usePage } from '@inertiajs/react';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { useEffect, useMemo, useState } from 'react';
import { addDays, formatDay, formatTime, getJson, localDate } from '@/utils/booking';

function dateRange(first, last) {
    const dates = [];
    for (let date = first; date <= last && dates.length < 120; date = addDays(date, 1)) {
        dates.push(date);
    }

    return dates;
}

export default function ManageBooking({ appointment, booking, locale }) {
    const { flash } = usePage().props;
    const [mode, setMode] = useState('view');
    const [date, setDate] = useState(booking?.window?.first ?? '');
    const [resourceId, setResourceId] = useState(booking?.resource_id ?? null);
    const [slots, setSlots] = useState({ loading: false, items: [], failed: false });
    const cancelForm = useForm({});
    const moveForm = useForm({ starts_at: '', resource_id: booking?.resource_id ?? null });

    const dates = useMemo(() => (booking ? dateRange(booking.window.first, booking.window.last) : []), [booking]);
    useEffect(() => {
        if (mode !== 'reschedule' || !booking || !date) {
            return undefined;
        }

        let cancelled = false;
        setSlots((current) => ({ ...current, loading: true, failed: false }));
        getJson(`/booking/manage/${appointment.id}/slots`, {
            date,
            resource_id: resourceId,
        })
            .then((data) => !cancelled && setSlots({ loading: false, items: data.slots, failed: false }))
            .catch(() => !cancelled && setSlots({ loading: false, items: [], failed: true }));

        return () => {
            cancelled = true;
        };
    }, [mode, booking, date, resourceId, appointment.id]);

    const cancel = (event) => {
        event.preventDefault();
        cancelForm.post(`/booking/manage/${appointment.id}/cancel`, { preserveScroll: true });
    };

    const reschedule = (event) => {
        event.preventDefault();
        moveForm.transform((data) => ({ ...data, resource_id: resourceId }));
        moveForm.post(`/booking/manage/${appointment.id}/reschedule`, {
            preserveScroll: true,
            onSuccess: () => setMode('view'),
        });
    };

    return (
        <div className="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 antialiased">
            <Head title="Manage booking" />
            <div className="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <h1 className="text-xl font-semibold">Your booking</h1>
                {flash?.success ? (
                    <p className="mt-3 flex items-center gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">
                        <CheckCircleIcon sx={{ fontSize: 18 }} />
                        {flash.success}
                    </p>
                ) : null}

                <dl className="mt-6 space-y-3 text-sm">
                    {appointment.customer ? (
                        <div>
                            <dt className="text-slate-500">Name</dt>
                            <dd className="font-medium">{appointment.customer}</dd>
                        </div>
                    ) : null}
                    {appointment.service ? (
                        <div>
                            <dt className="text-slate-500">Service</dt>
                            <dd className="font-medium">{appointment.service}</dd>
                        </div>
                    ) : null}
                    {appointment.resource ? (
                        <div>
                            <dt className="text-slate-500">With</dt>
                            <dd className="font-medium">{appointment.resource}</dd>
                        </div>
                    ) : null}
                    <div>
                        <dt className="text-slate-500">When</dt>
                        <dd className="font-medium">
                            {formatDay(localDate(appointment.starts_at, locale.timezone))} at {formatTime(appointment.starts_at, locale.timezone)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-slate-500">Status</dt>
                        <dd className="font-medium capitalize">{appointment.status.replace('_', ' ')}</dd>
                    </div>
                </dl>

                {!appointment.can_change ? (
                    <p className="mt-6 text-sm text-slate-600">
                        This booking can no longer be changed online. Please contact the business if you need help.
                    </p>
                ) : mode === 'view' ? (
                    <div className="mt-8 flex flex-wrap gap-3">
                        <button type="button" onClick={() => setMode('reschedule')} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">
                            Change time
                        </button>
                        <button type="button" onClick={() => setMode('cancel')} className="rounded-lg border border-red-200 px-4 py-2 text-sm font-semibold text-red-700">
                            Cancel booking
                        </button>
                    </div>
                ) : null}

                {mode === 'cancel' && appointment.can_change ? (
                    <form onSubmit={cancel} className="mt-8 space-y-4 border-t border-slate-100 pt-6">
                        <p className="text-sm text-slate-700">Cancel this booking? The time will become free for others.</p>
                        {cancelForm.errors.appointment ? <p className="text-sm text-red-600">{cancelForm.errors.appointment}</p> : null}
                        <div className="flex gap-3">
                            <button type="submit" disabled={cancelForm.processing} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                Yes, cancel
                            </button>
                            <button type="button" onClick={() => setMode('view')} className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700">
                                Keep booking
                            </button>
                        </div>
                    </form>
                ) : null}

                {mode === 'reschedule' && appointment.can_change && booking ? (
                    <form onSubmit={reschedule} className="mt-8 space-y-4 border-t border-slate-100 pt-6">
                        <p className="text-sm text-slate-700">Pick a new day and time.</p>
                        {booking.resources.length > 1 ? (
                            <label className="block text-sm">
                                <span className="text-slate-600">{booking.resource_label.singular}</span>
                                <select
                                    className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2"
                                    value={resourceId ?? ''}
                                    onChange={(event) => {
                                        const value = Number(event.target.value);
                                        setResourceId(value);
                                        moveForm.setData('resource_id', value);
                                        moveForm.setData('starts_at', '');
                                    }}
                                >
                                    {booking.resources.map((resource) => (
                                        <option key={resource.id} value={resource.id}>
                                            {resource.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ) : null}
                        <label className="block text-sm">
                            <span className="text-slate-600">Date</span>
                            <select className="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2" value={date} onChange={(event) => setDate(event.target.value)}>
                                {dates.map((value) => (
                                    <option key={value} value={value}>
                                        {formatDay(value)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div>
                            <p className="text-sm text-slate-600">Time</p>
                            {slots.loading ? <p className="mt-2 text-sm text-slate-500">Loading times…</p> : null}
                            {slots.failed ? <p className="mt-2 text-sm text-red-600">Could not load times. Try again.</p> : null}
                            {!slots.loading && !slots.failed && slots.items.length === 0 ? <p className="mt-2 text-sm text-slate-500">No free times on this day.</p> : null}
                            <div className="mt-2 flex flex-wrap gap-2">
                                {slots.items.map((slot) => (
                                    <button
                                        key={slot.starts_at}
                                        type="button"
                                        onClick={() => moveForm.setData('starts_at', slot.starts_at)}
                                        className={`rounded-lg border px-3 py-1.5 text-sm ${moveForm.data.starts_at === slot.starts_at ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200'}`}
                                    >
                                        {slot.time}
                                    </button>
                                ))}
                            </div>
                            {moveForm.errors.starts_at ? <p className="mt-2 text-sm text-red-600">{moveForm.errors.starts_at}</p> : null}
                            {moveForm.errors.appointment ? <p className="mt-2 text-sm text-red-600">{moveForm.errors.appointment}</p> : null}
                        </div>
                        <div className="flex gap-3">
                            <button type="submit" disabled={moveForm.processing || !moveForm.data.starts_at} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                Save new time
                            </button>
                            <button type="button" onClick={() => setMode('view')} className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-700">
                                Back
                            </button>
                        </div>
                    </form>
                ) : null}
            </div>
        </div>
    );
}

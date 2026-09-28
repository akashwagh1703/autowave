import Alert from '@mui/material/Alert';
import CircularProgress from '@mui/material/CircularProgress';
import IconButton from '@mui/material/IconButton';
import TextField from '@mui/material/TextField';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import { useEffect, useState } from 'react';
import useTenant from '@/hooks/useTenant';
import { addDays, formatDay, getJson } from '@/utils/booking';
import { formatPrice } from '@/utils/format';

const PERIODS = [
    { label: 'Morning', test: (time) => time < '12:00' },
    { label: 'Afternoon', test: (time) => time >= '12:00' && time < '17:00' },
    { label: 'Evening', test: (time) => time >= '17:00' },
];

/**
 * Pick a date and a start time for a resource. Free slots come from the server (working hours,
 * time off and existing bookings in the tenant timezone); a custom time can still be typed.
 */
export default function SlotPicker({ resourceId, serviceId, duration, ignoreId, date, time, today, onDateChange, onTimeChange, error }) {
    const { currency } = useTenant();
    const [state, setState] = useState({ loading: false, slots: [], windows: [], failed: false });
    const ready = Boolean(resourceId && date && (serviceId || duration));

    useEffect(() => {
        if (!ready) {
            setState({ loading: false, slots: [], windows: [], failed: false });

            return undefined;
        }

        let cancelled = false;
        setState((current) => ({ ...current, loading: true, failed: false }));

        getJson('/appointments/availability', { resource: resourceId, date, service: serviceId, duration, ignore: ignoreId })
            .then((data) => !cancelled && setState({ loading: false, slots: data.slots, windows: data.windows, failed: false }))
            .catch(() => !cancelled && setState({ loading: false, slots: [], windows: [], failed: true }));

        return () => {
            cancelled = true;
        };
    }, [ready, resourceId, serviceId, duration, ignoreId, date]);

    const closed = ready && !state.loading && !state.failed && state.windows.length === 0;

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <IconButton aria-label="Previous day" disabled={!date || date <= today} onClick={() => onDateChange(addDays(date, -1))}>
                    <ChevronLeftIcon />
                </IconButton>
                <TextField
                    type="date"
                    size="small"
                    label="Date"
                    value={date}
                    onChange={(event) => onDateChange(event.target.value)}
                    slotProps={{ htmlInput: { min: today }, inputLabel: { shrink: true } }}
                    className="w-44"
                />
                <IconButton aria-label="Next day" disabled={!date} onClick={() => onDateChange(addDays(date, 1))}>
                    <ChevronRightIcon />
                </IconButton>
                <span className="text-sm text-slate-600">{date ? formatDay(date) : ''}</span>
            </div>

            {!ready ? (
                <p className="text-sm text-slate-500">Choose who to book and a service or duration to see free times.</p>
            ) : state.loading ? (
                <div className="flex items-center gap-2 text-sm text-slate-500">
                    <CircularProgress size={16} /> Checking availability…
                </div>
            ) : state.failed ? (
                <Alert severity="warning">Could not load free times. You can still enter a time below.</Alert>
            ) : closed ? (
                <p className="text-sm text-slate-600">Closed on this day. Pick another date, or enter a time and allow booking outside working hours.</p>
            ) : (
                <div className="space-y-3">
                    <p className="text-xs text-slate-500">
                        Open {state.windows.map((window) => `${window.starts_at}–${window.ends_at}`).join(', ')}
                        {state.slots.length === 0 ? ' · fully booked' : ` · ${state.slots.length} free ${state.slots.length === 1 ? 'slot' : 'slots'}`}
                    </p>
                    {PERIODS.map((period) => {
                        const slots = state.slots.filter((slot) => period.test(slot.time));

                        return slots.length ? (
                            <div key={period.label}>
                                <p className="mb-1 text-xs font-medium tracking-wide text-slate-500 uppercase">{period.label}</p>
                                <div className="flex flex-wrap gap-2" role="group" aria-label={`${period.label} slots`}>
                                    {slots.map((slot) => (
                                        <button
                                            key={slot.starts_at}
                                            type="button"
                                            onClick={() => onTimeChange(slot.time)}
                                            aria-pressed={time === slot.time}
                                            className={`rounded-md border px-3 py-1.5 text-sm transition ${time === slot.time ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 bg-white text-slate-800 hover:border-brand-400'}`}
                                        >
                                            {slot.time}
                                            {slot.price != null ? <span className="ml-1 text-xs opacity-80">· {formatPrice(slot.price, currency)}</span> : null}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ) : null;
                    })}
                </div>
            )}

            <TextField
                type="time"
                size="small"
                label="Start time"
                value={time ?? ''}
                onChange={(event) => onTimeChange(event.target.value)}
                error={Boolean(error)}
                helperText={error ?? 'Pick a slot above or type a time.'}
                slotProps={{ htmlInput: { step: 300 }, inputLabel: { shrink: true } }}
                className="w-44"
            />
        </div>
    );
}

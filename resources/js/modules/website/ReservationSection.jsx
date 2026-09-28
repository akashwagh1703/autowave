import { useForm } from '@inertiajs/react';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { useEffect, useMemo, useState } from 'react';
import { addDays, formatDay, formatTime, getJson, localDate } from '@/utils/booking';
import { ActionButton, Card, Field, Honeypot, Section, SectionHeading, inputClass, useSite } from './site';

function dateRange(first, last) {
    const dates = [];
    for (let date = first; date <= last && dates.length < 120; date = addDays(date, 1)) {
        dates.push(date);
    }

    return dates;
}

function Choice({ selected, onClick, children, className = '' }) {
    const { theme } = useSite();

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={`border px-3 py-2 text-sm transition ${selected ? 'text-white' : 'border-slate-200 bg-white text-slate-800 hover:border-slate-400'} ${className}`}
            style={{ borderRadius: theme.radius, ...(selected ? { backgroundColor: theme.color, borderColor: theme.color } : {}) }}
        >
            {children}
        </button>
    );
}

export default function ReservationSection({ config }) {
    const { reservation, locale, reservationConfirmation } = useSite();
    const [date, setDate] = useState(reservation.first_date);
    const [slots, setSlots] = useState({ loading: true, items: [], failed: false });
    const [refresh, setRefresh] = useState(0);
    const [showConfirmation, setShowConfirmation] = useState(Boolean(reservationConfirmation));
    const form = useForm({ party_size: 2, starts_at: '', name: '', phone: '', email: '', notes: '', company_website: '' });

    const dates = useMemo(() => dateRange(reservation.first_date, reservation.last_date), [reservation.first_date, reservation.last_date]);
    const sizes = useMemo(() => Array.from({ length: reservation.max_party_size }, (_, index) => index + 1), [reservation.max_party_size]);

    useEffect(() => {
        setShowConfirmation(Boolean(reservationConfirmation));
    }, [reservationConfirmation]);

    useEffect(() => {
        form.setData('starts_at', '');
        let cancelled = false;
        setSlots((current) => ({ ...current, loading: true, failed: false }));
        getJson('/reservations/slots', { date })
            .then((data) => !cancelled && setSlots({ loading: false, items: data.slots, failed: false }))
            .catch(() => !cancelled && setSlots({ loading: false, items: [], failed: true }));

        return () => {
            cancelled = true;
        };
    }, [date, refresh]);

    const submit = (event) => {
        event.preventDefault();
        form.post('/reservations', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: (errors) => errors.starts_at && setRefresh((value) => value + 1),
        });
    };

    if (showConfirmation && reservationConfirmation) {
        return (
            <Section id="reservation">
                <SectionHeading title={config.heading} />
                <Card className="mx-auto max-w-xl py-10 text-center">
                    <CheckCircleIcon className="text-emerald-600" sx={{ fontSize: 48 }} />
                    <p className="mt-3 text-lg font-semibold text-slate-900" role="status">
                        {config.success_message}
                    </p>
                    <p className="mt-3 text-slate-700">
                        Table for {reservationConfirmation.party_size}
                        <br />
                        {formatDay(localDate(reservationConfirmation.reserved_at, locale.timezone))} at {formatTime(reservationConfirmation.reserved_at, locale.timezone)}
                    </p>
                    <p className="mt-3 text-sm text-slate-500">
                        {reservationConfirmation.status === 'pending' ? 'We will confirm your table shortly.' : 'Your table is confirmed. See you soon!'}
                    </p>
                    <ActionButton variant="secondary" className="mt-6" onClick={() => setShowConfirmation(false)}>
                        Make another reservation
                    </ActionButton>
                </Card>
            </Section>
        );
    }

    return (
        <Section id="reservation">
            <SectionHeading title={config.heading} intro={config.intro} />
            <Card className="mx-auto max-w-3xl space-y-6 sm:p-8">
                <div>
                    <h3 className="mb-3 font-semibold text-slate-900">How many guests?</h3>
                    <div className="flex flex-wrap gap-2" role="group" aria-label="Guests">
                        {sizes.map((size) => (
                            <Choice key={size} selected={form.data.party_size === size} onClick={() => form.setData('party_size', size)} className="min-w-11 text-center">
                                {size}
                            </Choice>
                        ))}
                    </div>
                    <p className="mt-2 text-xs text-slate-500">For more than {reservation.max_party_size} guests, please call us.</p>
                    {form.errors.party_size ? (
                        <p className="mt-2 text-sm text-red-600" role="alert">
                            {form.errors.party_size}
                        </p>
                    ) : null}
                </div>

                <div className="border-t border-slate-100 pt-6">
                    <h3 className="mb-3 font-semibold text-slate-900">Pick a date and time</h3>
                    <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2" role="group" aria-label="Dates">
                        {dates.map((item) => (
                            <Choice key={item} selected={item === date} onClick={() => setDate(item)} className="shrink-0 text-center">
                                <span className="block text-xs uppercase opacity-80">{formatDay(item, { weekday: 'short' })}</span>
                                <span className="block font-semibold">{formatDay(item, { day: 'numeric', month: 'short' })}</span>
                            </Choice>
                        ))}
                    </div>
                    <div className="mt-4">
                        {slots.loading ? (
                            <p className="text-sm text-slate-500">Checking times…</p>
                        ) : slots.failed ? (
                            <p className="text-sm text-red-600">Could not load times. Please try again.</p>
                        ) : slots.items.length === 0 ? (
                            <p className="text-sm text-slate-600">No times left on this day. Please pick another date.</p>
                        ) : (
                            <div className="flex flex-wrap gap-2" role="group" aria-label="Times">
                                {slots.items.map((slot) => (
                                    <Choice key={slot.starts_at} selected={form.data.starts_at === slot.starts_at} onClick={() => form.setData('starts_at', slot.starts_at)}>
                                        {slot.time}
                                    </Choice>
                                ))}
                            </div>
                        )}
                        {form.errors.starts_at ? (
                            <p className="mt-2 text-sm text-red-600" role="alert">
                                {form.errors.starts_at}
                            </p>
                        ) : null}
                    </div>
                </div>

                {form.data.starts_at ? (
                    <form onSubmit={submit} className="relative space-y-4 border-t border-slate-100 pt-6" noValidate>
                        <Honeypot value={form.data.company_website} onChange={(value) => form.setData('company_website', value)} />
                        <h3 className="font-semibold text-slate-900">Your details</h3>
                        <p className="text-sm text-slate-600">
                            Table for {form.data.party_size} · {formatDay(date)} at {formatTime(form.data.starts_at, locale.timezone)}
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Name" required error={form.errors.name}>
                                <input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoComplete="name" maxLength={120} />
                            </Field>
                            <Field label="Phone" required error={form.errors.phone}>
                                <input className={inputClass} type="tel" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} autoComplete="tel" maxLength={20} />
                            </Field>
                        </div>
                        <Field label="Email" error={form.errors.email}>
                            <input className={inputClass} type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} autoComplete="email" maxLength={255} />
                        </Field>
                        <Field label="Notes" hint="Occasion, seating preference or allergies" error={form.errors.notes}>
                            <textarea className={inputClass} rows={3} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={500} />
                        </Field>
                        {form.errors.throttle ? (
                            <p className="text-sm text-red-600" role="alert">
                                {form.errors.throttle}
                            </p>
                        ) : null}
                        <ActionButton type="submit" disabled={form.processing} className="w-full sm:w-auto">
                            {form.processing ? 'Sending…' : 'Request table'}
                        </ActionButton>
                    </form>
                ) : null}
            </Card>
        </Section>
    );
}

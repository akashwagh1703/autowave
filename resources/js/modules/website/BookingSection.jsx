import { useForm } from '@inertiajs/react';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import { useEffect, useMemo, useState } from 'react';
import { addDays, formatDay, formatDuration, formatTime, getJson, localDate } from '@/utils/booking';
import { formatMoney } from '@/utils/format';
import { ActionButton, Card, Field, Honeypot, Section, SectionHeading, inputClass, useSite } from './site';

const PERIODS = [
    { label: 'Morning', test: (time) => time < '12:00' },
    { label: 'Afternoon', test: (time) => time >= '12:00' && time < '17:00' },
    { label: 'Evening', test: (time) => time >= '17:00' },
];

function Step({ number, title, children }) {
    const { theme } = useSite();

    return (
        <div className="border-t border-slate-100 pt-6 first:border-t-0 first:pt-0">
            <h3 className="mb-3 flex items-center gap-3 font-semibold text-slate-900">
                <span className="flex h-7 w-7 items-center justify-center rounded-full text-sm text-white" style={{ backgroundColor: theme.color }}>
                    {number}
                </span>
                {title}
            </h3>
            {children}
        </div>
    );
}

function Choice({ selected, onClick, children, className = '' }) {
    const { theme } = useSite();

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={`border px-3 py-2 text-left text-sm transition ${selected ? 'text-white' : 'border-slate-200 bg-white text-slate-800 hover:border-slate-400'} ${className}`}
            style={{ borderRadius: theme.radius, ...(selected ? { backgroundColor: theme.color, borderColor: theme.color } : {}) }}
        >
            {children}
        </button>
    );
}

function dateRange(first, last) {
    const dates = [];
    for (let date = first; date <= last && dates.length < 120; date = addDays(date, 1)) {
        dates.push(date);
    }

    return dates;
}

function Confirmation({ confirmation, message, onAgain }) {
    const { locale } = useSite();

    return (
        <Card className="mx-auto max-w-xl py-10 text-center">
            <CheckCircleIcon className="text-emerald-600" sx={{ fontSize: 48 }} />
            <p className="mt-3 text-lg font-semibold text-slate-900" role="status">
                {message}
            </p>
            <p className="mt-3 text-slate-700">
                {[confirmation.service, confirmation.resource].filter(Boolean).join(' with ')}
                <br />
                {formatDay(localDate(confirmation.starts_at, locale.timezone))} at {formatTime(confirmation.starts_at, locale.timezone)}
            </p>
            <p className="mt-3 text-sm text-slate-500">
                {confirmation.status === 'pending' ? 'We will confirm your booking shortly.' : 'Your booking is confirmed. See you soon!'}
            </p>
            {confirmation.manage_url ? (
                <p className="mt-4 text-sm">
                    <a href={confirmation.manage_url} className="font-semibold underline-offset-4 hover:underline" style={{ color: 'inherit' }}>
                        Change or cancel this booking
                    </a>
                </p>
            ) : null}
            <ActionButton variant="secondary" className="mt-6" onClick={onAgain}>
                Make another booking
            </ActionButton>
        </Card>
    );
}

export default function BookingSection({ config }) {
    const { booking, locale, selectedService, bookingConfirmation } = useSite();
    const [serviceId, setServiceId] = useState(selectedService?.id ?? null);
    const [resourceId, setResourceId] = useState(booking.allow_any_resource ? 'any' : null);
    const [date, setDate] = useState(booking.window.first);
    const [slots, setSlots] = useState({ loading: false, items: [], failed: false });
    const [refresh, setRefresh] = useState(0);
    const [showConfirmation, setShowConfirmation] = useState(Boolean(bookingConfirmation));
    const form = useForm({ starts_at: '', name: '', phone: '', email: '', notes: '', company_website: '' });

    const dates = useMemo(() => dateRange(booking.window.first, booking.window.last), [booking.window.first, booking.window.last]);
    const service = booking.services.find((item) => item.id === serviceId) ?? null;
    const resources = booking.resources.filter((resource) => !booking.uses_services || (serviceId && resource.service_ids.includes(serviceId)));
    const resourceChosen = resourceId === 'any' ? resources.length > 0 : resources.some((resource) => resource.id === resourceId);
    const ready = (!booking.uses_services || serviceId) && resourceChosen;
    const labels = booking.resource_label;
    const selectedSlot = slots.items.find((slot) => slot.starts_at === form.data.starts_at) ?? null;

    useEffect(() => {
        if (selectedService) {
            chooseService(selectedService.id);
            setShowConfirmation(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedService]);

    useEffect(() => {
        setShowConfirmation(Boolean(bookingConfirmation));
    }, [bookingConfirmation]);

    useEffect(() => {
        form.setData('starts_at', '');
        if (!ready) {
            setSlots({ loading: false, items: [], failed: false });

            return undefined;
        }

        let cancelled = false;
        setSlots((current) => ({ ...current, loading: true, failed: false }));
        getJson('/booking/slots', { service_id: serviceId, resource_id: resourceId === 'any' ? null : resourceId, date })
            .then((data) => !cancelled && setSlots({ loading: false, items: data.slots, failed: false }))
            .catch(() => !cancelled && setSlots({ loading: false, items: [], failed: true }));

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ready, serviceId, resourceId, date, refresh]);

    const chooseService = (id) => {
        setServiceId(id);
        if (resourceId !== 'any' && !booking.resources.some((resource) => resource.id === resourceId && resource.service_ids.includes(id))) {
            setResourceId(booking.allow_any_resource ? 'any' : null);
        }
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, service_id: serviceId, resource_id: resourceId === 'any' ? null : resourceId }));
        form.post('/booking', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
            onError: (errors) => errors.starts_at && setRefresh((value) => value + 1),
        });
    };

    if (showConfirmation && bookingConfirmation) {
        return (
            <Section id="booking">
                <SectionHeading title={config.heading} align="center" />
                <Confirmation confirmation={bookingConfirmation} message={config.success_message} onAgain={() => setShowConfirmation(false)} />
            </Section>
        );
    }

    let step = 1;

    return (
        <Section id="booking">
            <SectionHeading title={config.heading} intro={config.intro} align="center" />
            <Card className="mx-auto max-w-3xl space-y-6 sm:p-8">
                {booking.uses_services ? (
                    <Step number={step++} title="Choose a service">
                        <div className="grid gap-2 sm:grid-cols-2">
                            {booking.services.map((item) => (
                                <Choice key={item.id} selected={item.id === serviceId} onClick={() => chooseService(item.id)}>
                                    <span className="block font-medium">{item.name}</span>
                                    <span className="block text-xs opacity-80">
                                        {[formatDuration(item.duration_minutes), item.price !== null ? formatMoney(item.price, locale.currency) : null].filter(Boolean).join(' · ')}
                                    </span>
                                </Choice>
                            ))}
                        </div>
                    </Step>
                ) : null}

                {!booking.uses_services || serviceId ? (
                    <Step number={step++} title={`Choose a ${labels.singular.toLowerCase()}`}>
                        {resources.length === 0 ? (
                            <p className="text-sm text-slate-600">Nobody offers this service online right now. Please contact us to book.</p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {booking.allow_any_resource ? (
                                    <Choice selected={resourceId === 'any'} onClick={() => setResourceId('any')}>
                                        Any available
                                    </Choice>
                                ) : null}
                                {resources.map((resource) => (
                                    <Choice key={resource.id} selected={resourceId === resource.id} onClick={() => setResourceId(resource.id)}>
                                        <span className="block">{resource.name}</span>
                                        {resource.hourly_rate != null ? (
                                            <span className="block text-xs opacity-80">{formatMoney(resource.hourly_rate, locale.currency)} / hour</span>
                                        ) : null}
                                    </Choice>
                                ))}
                            </div>
                        )}
                    </Step>
                ) : null}

                {ready ? (
                    <Step number={step++} title="Pick a date and time">
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
                                <p className="text-sm text-slate-500">Checking free times…</p>
                            ) : slots.failed ? (
                                <p className="text-sm text-red-600">Could not load free times. Please try again.</p>
                            ) : slots.items.length === 0 ? (
                                <p className="text-sm text-slate-600">No free times on this day. Please pick another date.</p>
                            ) : (
                                <div className="space-y-3">
                                    {PERIODS.map((period) => {
                                        const items = slots.items.filter((slot) => period.test(slot.time));

                                        return items.length ? (
                                            <div key={period.label}>
                                                <p className="mb-1 text-xs font-medium tracking-wide text-slate-500 uppercase">{period.label}</p>
                                                <div className="flex flex-wrap gap-2" role="group" aria-label={`${period.label} times`}>
                                                    {items.map((slot) => (
                                                        <Choice key={slot.starts_at} selected={form.data.starts_at === slot.starts_at} onClick={() => form.setData('starts_at', slot.starts_at)}>
                                                            <span className="block">{slot.time}</span>
                                                            {slot.price != null ? (
                                                                <span className="block text-xs opacity-80">
                                                                    {slot.price_varies ? 'from ' : ''}
                                                                    {formatMoney(slot.price, locale.currency)}
                                                                </span>
                                                            ) : null}
                                                        </Choice>
                                                    ))}
                                                </div>
                                            </div>
                                        ) : null;
                                    })}
                                </div>
                            )}
                            {form.errors.starts_at ? (
                                <p className="mt-2 text-sm text-red-600" role="alert">
                                    {form.errors.starts_at}
                                </p>
                            ) : null}
                            {form.errors.service_id || form.errors.resource_id ? (
                                <p className="mt-2 text-sm text-red-600" role="alert">
                                    {form.errors.service_id || form.errors.resource_id}
                                </p>
                            ) : null}
                        </div>
                    </Step>
                ) : null}

                {form.data.starts_at ? (
                    <Step number={step++} title="Your details">
                        <form onSubmit={submit} className="relative space-y-4" noValidate>
                            <Honeypot value={form.data.company_website} onChange={(value) => form.setData('company_website', value)} />
                            <p className="text-sm text-slate-600">
                                {service ? `${service.name} · ` : ''}
                                {formatDay(date)} at {formatTime(form.data.starts_at, locale.timezone)} ({formatDuration(service?.duration_minutes ?? booking.duration_minutes)})
                                {selectedSlot?.price != null ? ` · ${selectedSlot.price_varies ? 'from ' : ''}${formatMoney(selectedSlot.price, locale.currency)}` : ''}
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
                            <Field label="Notes" error={form.errors.notes}>
                                <textarea className={inputClass} rows={3} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={500} />
                            </Field>
                            {form.errors.throttle ? (
                                <p className="text-sm text-red-600" role="alert">
                                    {form.errors.throttle}
                                </p>
                            ) : null}
                            <ActionButton type="submit" disabled={form.processing} className="w-full sm:w-auto">
                                {form.processing ? 'Booking…' : 'Confirm booking'}
                            </ActionButton>
                        </form>
                    </Step>
                ) : null}
            </Card>
        </Section>
    );
}

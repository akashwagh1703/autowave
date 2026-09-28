import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import CustomerPicker from '@/modules/booking/CustomerPicker';
import SlotPicker from '@/modules/booking/SlotPicker';
import useTenant from '@/hooks/useTenant';
import { formatMoney } from '@/utils/format';
import { formatDuration } from '@/utils/booking';

function Section({ step, title, children }) {
    return (
        <section className="space-y-3">
            <h2 className="flex items-center gap-2 font-semibold text-slate-900">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-brand-100 text-xs text-brand-700">{step}</span>
                {title}
            </h2>
            {children}
        </section>
    );
}

export default function Create({ resources, services, customer, prefill, autoConfirm, today }) {
    const { currency, hasEngine, resourceLabel } = useTenant();
    const servicesEnabled = hasEngine('service');
    const [mode, setMode] = useState('existing');
    const [picked, setPicked] = useState(customer);

    const form = useForm({
        customer_id: customer?.id ?? null,
        customer: { name: '', phone: '', email: '' },
        service_id: prefill.service_id,
        booking_resource_id: prefill.booking_resource_id,
        date: prefill.date,
        time: prefill.time ?? '',
        duration_minutes: servicesEnabled ? '' : 60,
        price: '',
        notes: '',
        status: autoConfirm ? 'confirmed' : 'pending',
        allow_outside_hours: false,
    });

    const service = services.find((item) => item.id === Number(form.data.service_id)) ?? null;
    const resource = resources.find((item) => item.id === Number(form.data.booking_resource_id)) ?? null;
    const resourceOptions = service ? resources.filter((item) => item.service_ids?.includes(service.id)) : resources;
    const duration = Number(form.data.duration_minutes) || service?.duration_minutes || null;

    const chooseService = (id) => {
        const next = services.find((item) => item.id === Number(id));
        const keepResource = !next || !resource || resource.service_ids?.includes(next.id);
        form.setData((data) => ({ ...data, service_id: id || null, booking_resource_id: keepResource ? data.booking_resource_id : null, duration_minutes: '', price: '' }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform(({ date, time, customer: inline, customer_id, ...data }) => ({
            ...data,
            ...(mode === 'existing' ? { customer_id } : { customer: inline }),
            starts_at: date && time ? `${date}T${time}` : null,
            duration_minutes: data.duration_minutes === '' ? null : data.duration_minutes,
            price: data.price === '' ? null : data.price,
        }));
        form.post('/appointments');
    };

    return (
        <AppLayout title="Book appointment">
            <Button component={Link} href="/appointments" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Calendar
            </Button>
            <PageHeader title="Book appointment" description="Walk-in, phone or WhatsApp booking. Free times respect working hours, time off and existing bookings." />

            {resources.length === 0 ? (
                <Alert severity="info">
                    Add a {resourceLabel.singular.toLowerCase()} with working hours before booking. <Link href="/resources/create" className="font-medium underline">Add one now</Link>.
                </Alert>
            ) : (
                <Card variant="outlined" className="max-w-4xl">
                    <CardContent>
                        <form onSubmit={submit} noValidate className="space-y-8">
                            <Section step={1} title="Customer">
                                <ToggleButtonGroup
                                    size="small"
                                    exclusive
                                    value={mode}
                                    onChange={(_, next) => next && setMode(next)}
                                    aria-label="Customer type"
                                >
                                    <ToggleButton value="existing">Existing customer</ToggleButton>
                                    <ToggleButton value="new">New customer</ToggleButton>
                                </ToggleButtonGroup>
                                {mode === 'existing' ? (
                                    <CustomerPicker
                                        value={picked}
                                        autoFocus={!customer}
                                        onChange={(next) => {
                                            setPicked(next);
                                            form.setData('customer_id', next?.id ?? null);
                                        }}
                                        error={form.errors.customer_id}
                                    />
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <TextField
                                            label="Name"
                                            required
                                            value={form.data.customer.name}
                                            onChange={(event) => form.setData('customer', { ...form.data.customer, name: event.target.value })}
                                            error={Boolean(form.errors['customer.name'] || form.errors.customer_id)}
                                            helperText={form.errors['customer.name'] ?? form.errors.customer_id}
                                            slotProps={{ htmlInput: { maxLength: 150 } }}
                                        />
                                        <TextField
                                            label="Phone"
                                            type="tel"
                                            value={form.data.customer.phone}
                                            onChange={(event) => form.setData('customer', { ...form.data.customer, phone: event.target.value })}
                                            error={Boolean(form.errors['customer.phone'])}
                                            helperText={form.errors['customer.phone'] ?? 'An existing customer with this number is reused.'}
                                        />
                                        <TextField
                                            label="Email"
                                            type="email"
                                            value={form.data.customer.email}
                                            onChange={(event) => form.setData('customer', { ...form.data.customer, email: event.target.value })}
                                            error={Boolean(form.errors['customer.email'])}
                                            helperText={form.errors['customer.email']}
                                        />
                                    </div>
                                )}
                            </Section>

                            <Section step={2} title={servicesEnabled ? `Service and ${resourceLabel.singular.toLowerCase()}` : resourceLabel.singular}>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {servicesEnabled ? (
                                        <TextField
                                            select
                                            label="Service"
                                            value={form.data.service_id ?? ''}
                                            onChange={(event) => chooseService(event.target.value)}
                                            error={Boolean(form.errors.service_id)}
                                            helperText={form.errors.service_id ?? 'Optional. Sets the duration and price.'}
                                            slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
                                        >
                                            <MenuItem value="">
                                                <em>No specific service</em>
                                            </MenuItem>
                                            {services.map((item) => (
                                                <MenuItem key={item.id} value={item.id}>
                                                    {item.name} · {formatDuration(item.duration_minutes)} · {formatMoney(item.price, currency)}
                                                </MenuItem>
                                            ))}
                                        </TextField>
                                    ) : null}
                                    <TextField
                                        select
                                        label={resourceLabel.singular}
                                        required
                                        value={form.data.booking_resource_id ?? ''}
                                        onChange={(event) => form.setData('booking_resource_id', event.target.value || null)}
                                        error={Boolean(form.errors.booking_resource_id)}
                                        helperText={
                                            form.errors.booking_resource_id ??
                                            (service && resourceOptions.length === 0 ? `No ${resourceLabel.plural.toLowerCase()} offer this service yet.` : null)
                                        }
                                    >
                                        {resourceOptions.map((item) => (
                                            <MenuItem key={item.id} value={item.id}>
                                                <span className="mr-2 inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: item.color }} />
                                                {item.name}
                                            </MenuItem>
                                        ))}
                                    </TextField>
                                    <TextField
                                        label="Duration"
                                        type="number"
                                        required={!service}
                                        value={form.data.duration_minutes}
                                        placeholder={service ? String(service.duration_minutes) : ''}
                                        onChange={(event) => form.setData('duration_minutes', event.target.value)}
                                        error={Boolean(form.errors.duration_minutes)}
                                        helperText={form.errors.duration_minutes ?? (service ? `Default ${formatDuration(service.duration_minutes)}; change for this booking only.` : null)}
                                        slotProps={{
                                            htmlInput: { min: 5, max: 720, step: 5 },
                                            input: { endAdornment: <InputAdornment position="end">min</InputAdornment> },
                                            inputLabel: { shrink: true },
                                        }}
                                    />
                                </div>
                            </Section>

                            <Section step={3} title="Date and time">
                                <SlotPicker
                                    resourceId={form.data.booking_resource_id}
                                    serviceId={form.data.service_id}
                                    duration={duration}
                                    date={form.data.date}
                                    time={form.data.time}
                                    today={today}
                                    onDateChange={(date) => form.setData((data) => ({ ...data, date, time: '' }))}
                                    onTimeChange={(time) => form.setData('time', time)}
                                    error={form.errors.starts_at}
                                />
                                <FormControlLabel
                                    control={<Checkbox checked={form.data.allow_outside_hours} onChange={(event) => form.setData('allow_outside_hours', event.target.checked)} />}
                                    label={<span className="text-sm">Allow booking outside working hours (time off and existing bookings are still respected)</span>}
                                />
                            </Section>

                            <Section step={4} title="Details">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <TextField
                                        label="Price"
                                        type="number"
                                        value={form.data.price}
                                        placeholder={service ? String(service.price) : ''}
                                        onChange={(event) => form.setData('price', event.target.value)}
                                        error={Boolean(form.errors.price)}
                                        helperText={form.errors.price ?? (service ? 'Leave empty to use the service price.' : null)}
                                        slotProps={{
                                            htmlInput: { min: 0, step: '0.01' },
                                            input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> },
                                            inputLabel: { shrink: true },
                                        }}
                                    />
                                    <TextField select label="Status" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                        <MenuItem value="confirmed">Confirmed</MenuItem>
                                        <MenuItem value="pending">Pending — confirm later</MenuItem>
                                    </TextField>
                                    <TextField
                                        label="Notes"
                                        multiline
                                        minRows={2}
                                        value={form.data.notes}
                                        onChange={(event) => form.setData('notes', event.target.value)}
                                        error={Boolean(form.errors.notes)}
                                        helperText={form.errors.notes}
                                        slotProps={{ htmlInput: { maxLength: 2000 } }}
                                        className="sm:col-span-2"
                                    />
                                </div>
                            </Section>

                            <div className="flex justify-end gap-2">
                                <Button component={Link} href="/appointments" color="inherit">
                                    Cancel
                                </Button>
                                <Button type="submit" variant="contained" disabled={form.processing || !form.data.time || !form.data.booking_resource_id}>
                                    Book appointment
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}

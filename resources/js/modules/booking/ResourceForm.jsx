import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import RatesEditor from '@/modules/booking/RatesEditor';
import WorkingHoursEditor from '@/modules/booking/WorkingHoursEditor';
import useTenant from '@/hooks/useTenant';
import { formatDuration } from '@/utils/booking';

const COLORS = ['#6366f1', '#0ea5e9', '#16a34a', '#f59e0b', '#db2777', '#8b5cf6', '#ef4444', '#64748b'];

export default function ResourceForm({ form, onSubmit, submitLabel, cancelHref, members, services, servicesEnabled, resourceId = null }) {
    const { resourceLabel } = useTenant();
    const label = resourceLabel.singular.toLowerCase();
    const selectedServices = form.data.service_ids ?? [];
    const toggleService = (id) =>
        form.setData('service_ids', selectedServices.includes(id) ? selectedServices.filter((value) => value !== id) : [...selectedServices, id]);

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-8">
            <section className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Name"
                    required
                    autoFocus
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    error={Boolean(form.errors.name)}
                    helperText={form.errors.name}
                    slotProps={{ htmlInput: { maxLength: 120 } }}
                />
                <TextField
                    label="Short description"
                    value={form.data.description ?? ''}
                    onChange={(event) => form.setData('description', event.target.value)}
                    error={Boolean(form.errors.description)}
                    helperText={form.errors.description ?? 'e.g. Senior stylist, 5-a-side, Dentist'}
                    slotProps={{ htmlInput: { maxLength: 255 } }}
                />
                <TextField
                    select
                    label="Team member"
                    value={form.data.tenant_user_id ?? ''}
                    onChange={(event) => form.setData('tenant_user_id', event.target.value || null)}
                    error={Boolean(form.errors.tenant_user_id)}
                    helperText={form.errors.tenant_user_id ?? `Optional. Links this ${label} to someone who logs in, for “My schedule”.`}
                    slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
                >
                    <MenuItem value="">
                        <em>Not linked</em>
                    </MenuItem>
                    {members.map((member) => (
                        <MenuItem key={member.id} value={member.id} disabled={member.resource_id !== null && member.resource_id !== resourceId}>
                            {member.name} <span className="ml-2 text-xs text-slate-500">{member.email}</span>
                        </MenuItem>
                    ))}
                </TextField>
                <div>
                    <p className="mb-1 text-sm text-slate-600">Calendar colour</p>
                    <div className="flex flex-wrap items-center gap-2" role="radiogroup" aria-label="Calendar colour">
                        {COLORS.map((color) => (
                            <button
                                key={color}
                                type="button"
                                role="radio"
                                aria-checked={form.data.color === color}
                                aria-label={color}
                                onClick={() => form.setData('color', color)}
                                className={`h-8 w-8 rounded-full border-2 ${form.data.color === color ? 'border-slate-900' : 'border-white shadow'}`}
                                style={{ backgroundColor: color }}
                            />
                        ))}
                        <input
                            type="color"
                            value={form.data.color}
                            onChange={(event) => form.setData('color', event.target.value)}
                            aria-label="Custom colour"
                            className="h-8 w-8 cursor-pointer rounded border border-slate-200 bg-white p-0.5"
                        />
                    </div>
                    {form.errors.color ? <p className="mt-1 text-xs text-red-600">{form.errors.color}</p> : null}
                </div>
                <FormControlLabel
                    control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />}
                    label="Taking bookings"
                    className="sm:col-span-2"
                />
            </section>

            <section>
                <h2 className="font-semibold text-slate-900">Working hours</h2>
                <p className="mb-3 text-sm text-slate-600">
                    In your business timezone. Changing hours never moves existing appointments.
                </p>
                <WorkingHoursEditor value={form.data.working_hours} onChange={(value) => form.setData('working_hours', value)} errors={form.errors} />
            </section>

            <section>
                <h2 className="font-semibold text-slate-900">Pricing</h2>
                <p className="mb-3 text-sm text-slate-600">
                    {servicesEnabled
                        ? `Used for bookings without a service. The first matching rate applies; otherwise the hourly rate.`
                        : `The price of a booking is worked out from these rates and shown on your website. The first matching rate applies; otherwise the hourly rate.`}
                </p>
                <RatesEditor
                    hourlyRate={form.data.hourly_rate}
                    rates={form.data.rates ?? []}
                    onHourlyRateChange={(value) => form.setData('hourly_rate', value)}
                    onRatesChange={(value) => form.setData('rates', value)}
                    errors={form.errors}
                />
            </section>

            {servicesEnabled ? (
                <section>
                    <h2 className="font-semibold text-slate-900">Services</h2>
                    <p className="text-sm text-slate-600">What can be booked with this {label}.</p>
                    {form.errors.service_ids ? <p className="mt-1 text-sm text-red-600">{form.errors.service_ids}</p> : null}
                    {services.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">No services yet.</p>
                    ) : (
                        <>
                            <div className="mt-2 flex gap-2">
                                <Button size="small" onClick={() => form.setData('service_ids', services.map((service) => service.id))}>
                                    Select all
                                </Button>
                                <Button size="small" color="inherit" onClick={() => form.setData('service_ids', [])}>
                                    Clear
                                </Button>
                            </div>
                            <div className="mt-1 grid gap-1 sm:grid-cols-2">
                                {services.map((service) => (
                                    <FormControlLabel
                                        key={service.id}
                                        control={<Checkbox checked={selectedServices.includes(service.id)} onChange={() => toggleService(service.id)} />}
                                        label={
                                            <span className="text-sm">
                                                {service.name} <span className="text-slate-500">· {formatDuration(service.duration_minutes)}</span>
                                            </span>
                                        }
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </section>
            ) : null}

            <div className="flex justify-end gap-2">
                <Button component={Link} href={cancelHref} color="inherit">
                    Cancel
                </Button>
                <Button type="submit" variant="contained" disabled={form.processing}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}

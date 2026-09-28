import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';
import { formatDuration } from '@/utils/booking';

export default function Food({ settings, durationOptions, slotIntervalOptions, maxPartySizeLimit }) {
    const { can, hasModule } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({ ...settings });
    const locked = !canUpdate;

    const field = (name) => ({
        value: form.data[name] ?? '',
        disabled: locked,
        onChange: (event) => form.setData(name, event.target.value),
        error: Boolean(form.errors[name]),
    });

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/food', { preserveScroll: true });
    };

    return (
        <AppLayout title="Reservation settings">
            <Button component={Link} href="/settings" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Settings
            </Button>
            <PageHeader title="Reservation settings" description={canUpdate ? 'Table booking hours and rules, for your team and your website.' : 'You have view-only access.'} />

            <form onSubmit={submit} noValidate className="max-w-3xl space-y-4">
                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Online table booking</h2>
                        <FormControlLabel
                            control={<Switch checked={form.data.online} disabled={locked} onChange={(event) => form.setData('online', event.target.checked)} />}
                            label="Take table bookings from my website"
                        />
                        <p className="text-sm text-slate-600">
                            Guests pick a date, time and party size.
                            {hasModule('website') ? (
                                <>
                                    {' '}The form appears when the Reservations section is switched on under{' '}
                                    <Link href="/website" className="font-medium text-brand-700 hover:underline">
                                        Website
                                    </Link>
                                    .
                                </>
                            ) : null}
                        </p>
                        <FormControlLabel
                            control={<Switch checked={form.data.auto_confirm} disabled={locked || !form.data.online} onChange={(event) => form.setData('auto_confirm', event.target.checked)} />}
                            label="Confirm website bookings automatically"
                        />
                        <p className="text-sm text-slate-600">When off, website bookings wait as Pending until someone confirms them.</p>
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <h2 className="font-semibold text-slate-900 sm:col-span-2">Hours and slots</h2>
                        <TextField label="First booking" type="time" {...field('opens')} helperText={form.errors.opens} slotProps={{ inputLabel: { shrink: true } }} />
                        <TextField label="Last booking before" type="time" {...field('closes')} helperText={form.errors.closes} slotProps={{ inputLabel: { shrink: true } }} />
                        <TextField select label="Time slots every" {...field('slot_interval')} helperText={form.errors.slot_interval}>
                            {slotIntervalOptions.map((minutes) => (
                                <MenuItem key={minutes} value={minutes}>
                                    {formatDuration(minutes)}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField select label="A table is held for" {...field('duration_minutes')} helperText={form.errors.duration_minutes ?? 'Default length of a booking.'}>
                            {durationOptions.map((minutes) => (
                                <MenuItem key={minutes} value={minutes}>
                                    {formatDuration(minutes)}
                                </MenuItem>
                            ))}
                        </TextField>
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <h2 className="font-semibold text-slate-900 sm:col-span-3">Website booking rules</h2>
                        <TextField
                            label="Largest party online"
                            type="number"
                            {...field('max_party_size')}
                            helperText={form.errors.max_party_size ?? 'Bigger groups call you.'}
                            slotProps={{ htmlInput: { min: 1, max: maxPartySizeLimit } }}
                        />
                        <TextField
                            label="Minimum notice (minutes)"
                            type="number"
                            {...field('min_notice_minutes')}
                            helperText={form.errors.min_notice_minutes}
                            slotProps={{ htmlInput: { min: 0, max: 10080 } }}
                        />
                        <TextField
                            label="Book up to (days ahead)"
                            type="number"
                            {...field('max_days_ahead')}
                            helperText={form.errors.max_days_ahead}
                            slotProps={{ htmlInput: { min: 1, max: 365 } }}
                        />
                    </CardContent>
                </Card>

                {canUpdate ? (
                    <div className="flex justify-end">
                        <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                            Save settings
                        </Button>
                    </div>
                ) : null}
            </form>
        </AppLayout>
    );
}

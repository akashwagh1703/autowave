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
import WorkingHoursEditor from '@/modules/booking/WorkingHoursEditor';
import useTenant from '@/hooks/useTenant';
import { formatDuration } from '@/utils/booking';

export default function Booking({ settings, slotIntervals }) {
    const { can } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({ ...settings });

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/booking', { preserveScroll: true });
    };

    return (
        <AppLayout title="Booking settings">
            <Button component={Link} href="/settings" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Settings
            </Button>
            <PageHeader title="Booking settings" description={canUpdate ? 'How appointments are offered and confirmed.' : 'You have view-only access.'} />

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-3">
                <Card variant="outlined">
                    <CardContent className="space-y-4">
                        <TextField
                            label="What customers book"
                            fullWidth
                            disabled={!canUpdate}
                            value={form.data.resource_label}
                            onChange={(event) => form.setData('resource_label', event.target.value)}
                            error={Boolean(form.errors.resource_label)}
                            helperText={form.errors.resource_label ?? 'Singular, e.g. Stylist, Doctor, Turf, Court.'}
                            slotProps={{ htmlInput: { maxLength: 40 } }}
                        />
                        <TextField
                            select
                            label="Slot interval"
                            fullWidth
                            disabled={!canUpdate}
                            value={form.data.slot_interval}
                            onChange={(event) => form.setData('slot_interval', Number(event.target.value))}
                            error={Boolean(form.errors.slot_interval)}
                            helperText={form.errors.slot_interval ?? 'How often start times are offered, e.g. every 15 minutes.'}
                        >
                            {slotIntervals.map((minutes) => (
                                <MenuItem key={minutes} value={minutes}>
                                    Every {formatDuration(minutes)}
                                </MenuItem>
                            ))}
                        </TextField>
                        <div>
                            <FormControlLabel
                                control={<Switch checked={form.data.auto_confirm} disabled={!canUpdate} onChange={(event) => form.setData('auto_confirm', event.target.checked)} />}
                                label="Confirm new bookings automatically"
                            />
                            <p className="text-sm text-slate-600">When off, new bookings start as pending until someone confirms them.</p>
                        </div>
                    </CardContent>
                </Card>

                <Card variant="outlined" className="lg:col-span-2">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Default working hours</h2>
                        <p className="mb-3 text-sm text-slate-600">Used for newly added staff or resources. Each one can then have its own hours.</p>
                        <WorkingHoursEditor
                            value={form.data.default_hours}
                            onChange={(value) => form.setData('default_hours', value)}
                            errors={form.errors}
                            field="default_hours"
                            disabled={!canUpdate}
                        />
                    </CardContent>
                </Card>

                {canUpdate ? (
                    <div className="flex justify-end lg:col-span-3">
                        <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                            Save booking settings
                        </Button>
                    </div>
                ) : null}
            </form>
        </AppLayout>
    );
}

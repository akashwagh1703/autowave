import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';

const dayLabel = (days) => (days === 0 ? 'On the due date' : `${days} day${days === 1 ? '' : 's'} before`);

export default function Education({ settings, reminderDaysOptions, maxInstalments }) {
    const { can, hasModule } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({
        default_instalments: settings.default_instalments,
        reminder_days_before: settings.reminder_days_before,
    });

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/education', { preserveScroll: true });
    };

    return (
        <AppLayout title="Coaching settings">
            <Button component={Link} href="/settings" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Settings
            </Button>
            <PageHeader title="Coaching settings" description={canUpdate ? 'Admission defaults and fee reminders.' : 'You have view-only access.'} />

            <form onSubmit={submit} noValidate className="max-w-3xl space-y-4">
                <Card variant="outlined">
                    <CardContent className="space-y-4">
                        <h2 className="font-semibold text-slate-900">Admissions</h2>
                        <TextField
                            label="Default number of instalments"
                            type="number"
                            fullWidth
                            disabled={!canUpdate}
                            value={form.data.default_instalments}
                            onChange={(event) => form.setData('default_instalments', event.target.value)}
                            error={Boolean(form.errors.default_instalments)}
                            helperText={form.errors.default_instalments ?? `Pre-filled on the admission form, one month apart (1–${maxInstalments}).`}
                            slotProps={{ htmlInput: { min: 1, max: maxInstalments } }}
                        />
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-4">
                        <h2 className="font-semibold text-slate-900">Fee reminders</h2>
                        <TextField
                            select
                            label="Remind students"
                            fullWidth
                            disabled={!canUpdate}
                            value={form.data.reminder_days_before}
                            onChange={(event) => form.setData('reminder_days_before', event.target.value)}
                            error={Boolean(form.errors.reminder_days_before)}
                            helperText={form.errors.reminder_days_before}
                        >
                            {reminderDaysOptions.map((days) => (
                                <MenuItem key={days} value={days}>
                                    {dayLabel(days)}
                                </MenuItem>
                            ))}
                        </TextField>
                        <p className="text-sm text-slate-600">
                            AutoWave fires “Fee due soon” for each unpaid instalment at this point, and “Fee overdue” the day after it is due.
                            {hasModule('automation') ? (
                                <>
                                    {' '}Send the actual WhatsApp or SMS message with an{' '}
                                    <Link href="/automations" className="font-medium text-brand-700 hover:underline">
                                        automation
                                    </Link>{' '}
                                    on these triggers.
                                </>
                            ) : null}
                        </p>
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

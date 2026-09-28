import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';

const number = (value) => new Intl.NumberFormat().format(value ?? 0);

export default function Ai({ settings, status, usage, tones, notesMax, leadsEnabled }) {
    const { can } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({
        enabled: settings.enabled,
        auto_extract: settings.auto_extract,
        tone: settings.tone,
        notes: settings.notes ?? '',
    });
    const locked = !canUpdate || !form.data.enabled;
    const resets = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' }).format(new Date(usage.resets_at));

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/ai', { preserveScroll: true });
    };

    return (
        <AppLayout title="AI settings">
            <Button component={Link} href="/settings" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Settings
            </Button>
            <PageHeader
                title="AI settings"
                description={
                    canUpdate
                        ? 'AI suggests replies, summarises, fills in lead details and helps you write. It never sends anything by itself.'
                        : 'You have view-only access.'
                }
            />

            {!status.available && status.reason !== 'disabled' ? (
                <Alert severity={status.reason === 'limit' ? 'warning' : 'info'} className="mb-4 max-w-3xl">
                    {status.message}
                </Alert>
            ) : null}

            <form onSubmit={submit} noValidate className="max-w-3xl space-y-4">
                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">AI helpers</h2>
                        <FormControlLabel
                            control={<Switch checked={form.data.enabled} disabled={!canUpdate} onChange={(event) => form.setData('enabled', event.target.checked)} />}
                            label="Use AI in this business"
                        />
                        <p className="text-sm text-slate-600">
                            When off, the AI buttons are hidden and AI automation steps are skipped. Everything else keeps working.
                        </p>
                        {leadsEnabled ? (
                            <>
                                <FormControlLabel
                                    control={
                                        <Switch checked={form.data.auto_extract} disabled={locked} onChange={(event) => form.setData('auto_extract', event.target.checked)} />
                                    }
                                    label="Fill in lead details from WhatsApp and Instagram messages"
                                />
                                <p className="text-sm text-slate-600">
                                    A couple of minutes after a lead writes, AI fills in empty details (name, email, interest, budget). Details that are already
                                    filled are never overwritten; differences are shown on the lead for you to accept.
                                </p>
                            </>
                        ) : null}
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">How AI writes</h2>
                        <TextField
                            select
                            label="Tone"
                            fullWidth
                            value={form.data.tone}
                            disabled={locked}
                            onChange={(event) => form.setData('tone', event.target.value)}
                            error={Boolean(form.errors.tone)}
                            helperText={form.errors.tone ?? 'Used for suggested replies and writing help.'}
                        >
                            {tones.map((tone) => (
                                <MenuItem key={tone.value} value={tone.value}>
                                    {tone.label}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField
                            label="Notes for the AI"
                            fullWidth
                            multiline
                            minRows={3}
                            value={form.data.notes}
                            disabled={locked}
                            onChange={(event) => form.setData('notes', event.target.value)}
                            error={Boolean(form.errors.notes)}
                            helperText={
                                form.errors.notes ??
                                'Facts AI should know that are not on your website, e.g. "Parking is free", "We reply in Hindi or English". Do not add passwords or private data.'
                            }
                            slotProps={{ htmlInput: { maxLength: notesMax } }}
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

            <Card variant="outlined" className="mt-4 max-w-3xl">
                <CardContent className="space-y-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="font-semibold text-slate-900">This month’s usage</h2>
                        <span className="text-sm text-slate-500">Resets on {resets}</span>
                    </div>
                    <LinearProgress
                        variant="determinate"
                        value={usage.percent}
                        color={usage.percent >= 90 ? 'error' : usage.percent >= 70 ? 'warning' : 'primary'}
                        aria-label="AI allowance used"
                    />
                    <p className="text-sm text-slate-700">
                        {number(usage.used)} of {number(usage.cap)} tokens used ({usage.percent}%) · {number(usage.requests)} requests
                    </p>
                    {usage.features.length > 0 ? (
                        <ul className="divide-y divide-slate-100 text-sm">
                            {usage.features.map((feature) => (
                                <li key={feature.feature} className="flex justify-between py-2">
                                    <span className="text-slate-700">{feature.label}</span>
                                    <span className="text-slate-500">
                                        {number(feature.requests)} requests · {number(feature.tokens)} tokens
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-slate-500">No AI requests yet this month.</p>
                    )}
                    <p className="text-xs text-slate-500">
                        Tokens measure how much text AI reads and writes. When the monthly allowance is used up, AI buttons pause until the 1st.
                    </p>
                </CardContent>
            </Card>
        </AppLayout>
    );
}

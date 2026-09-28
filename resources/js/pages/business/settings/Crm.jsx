import { Link, router, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';

function move(list, index, delta) {
    const next = [...list];
    const target = index + delta;

    if (target < 0 || target >= next.length) {
        return next;
    }

    [next[index], next[target]] = [next[target], next[index]];

    return next;
}

function firstError(errors, prefix) {
    return errors[prefix] ?? Object.entries(errors).find(([key]) => key.startsWith(`${prefix}.`))?.[1];
}

function ReorderButtons({ index, count, onMove, disabled }) {
    return (
        <div className="flex">
            <IconButton size="small" aria-label="Move up" disabled={disabled || index === 0} onClick={() => onMove(index, -1)}>
                <ArrowUpwardIcon fontSize="small" />
            </IconButton>
            <IconButton size="small" aria-label="Move down" disabled={disabled || index === count - 1} onClick={() => onMove(index, 1)}>
                <ArrowDownwardIcon fontSize="small" />
            </IconButton>
        </div>
    );
}

function StagesEditor({ stages, outcomes, canUpdate }) {
    const form = useForm({ stages: stages.map(({ id, name, color, outcome, is_active, leads_count }) => ({ id, name, color, outcome, is_active, leads_count })) });
    const rows = form.data.stages;
    const setRow = (index, changes) => form.setData('stages', rows.map((row, i) => (i === index ? { ...row, ...changes } : row)));

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ stages: data.stages.map(({ leads_count, ...row }) => row) }));
        form.put('/settings/crm/stages', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="font-semibold text-slate-900">Pipeline stages</h2>
                    <p className="text-sm text-slate-600">
                        Rename and reorder to match how you sell. Every pipeline needs at least one active stage of each outcome.
                    </p>
                </div>
            </div>

            {firstError(form.errors, 'stages') ? (
                <Alert severity="error" className="mt-3">
                    {firstError(form.errors, 'stages')}
                </Alert>
            ) : null}

            <ul className="mt-4 space-y-3">
                {rows.map((row, index) => (
                    <li key={row.id ?? `new-${index}`} className={`flex flex-wrap items-center gap-3 rounded-lg border p-3 ${row.is_active ? 'border-slate-200' : 'border-dashed border-slate-300 bg-slate-50'}`}>
                        <ReorderButtons index={index} count={rows.length} disabled={!canUpdate} onMove={(i, delta) => form.setData('stages', move(rows, i, delta))} />
                        <input
                            type="color"
                            value={row.color}
                            disabled={!canUpdate}
                            onChange={(event) => setRow(index, { color: event.target.value })}
                            aria-label={`Colour for ${row.name || 'new stage'}`}
                            className="h-9 w-9 cursor-pointer rounded border border-slate-200 bg-white p-0.5"
                        />
                        <TextField
                            size="small"
                            label="Name"
                            value={row.name}
                            disabled={!canUpdate}
                            onChange={(event) => setRow(index, { name: event.target.value })}
                            error={Boolean(form.errors[`stages.${index}.name`])}
                            helperText={form.errors[`stages.${index}.name`]}
                            slotProps={{ htmlInput: { maxLength: 60 } }}
                            className="min-w-48 flex-1"
                        />
                        <TextField
                            select
                            size="small"
                            label="Outcome"
                            value={row.outcome}
                            disabled={!canUpdate || row.leads_count > 0}
                            onChange={(event) => setRow(index, { outcome: event.target.value })}
                            error={Boolean(form.errors[`stages.${index}.outcome`])}
                            helperText={form.errors[`stages.${index}.outcome`] ?? (row.leads_count > 0 ? 'Fixed: stage has leads' : null)}
                            className="w-44"
                        >
                            {outcomes.map((outcome) => (
                                <MenuItem key={outcome.value} value={outcome.value}>
                                    {outcome.label}
                                </MenuItem>
                            ))}
                        </TextField>
                        <FormControlLabel
                            control={<Switch checked={row.is_active} disabled={!canUpdate} onChange={(event) => setRow(index, { is_active: event.target.checked })} />}
                            label="Active"
                        />
                        <span className="text-xs text-slate-500">{row.leads_count ?? 0} {row.leads_count === 1 ? 'lead' : 'leads'}</span>
                    </li>
                ))}
            </ul>

            {canUpdate ? (
                <div className="mt-4 flex flex-wrap justify-between gap-2">
                    <Button
                        startIcon={<AddIcon />}
                        disabled={rows.length >= 20}
                        onClick={() => form.setData('stages', [...rows, { id: null, name: '', color: '#64748b', outcome: 'open', is_active: true, leads_count: 0 }])}
                    >
                        Add stage
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                        Save stages
                    </Button>
                </div>
            ) : null}
        </form>
    );
}

function SourcesEditor({ sources, canUpdate }) {
    const form = useForm({ sources: sources.map(({ id, name, is_active }) => ({ id, name, is_active })) });
    const rows = form.data.sources;
    const setRow = (index, changes) => form.setData('sources', rows.map((row, i) => (i === index ? { ...row, ...changes } : row)));

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/crm/sources', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit}>
            <h2 className="font-semibold text-slate-900">Lead sources</h2>
            <p className="text-sm text-slate-600">Where your enquiries come from. Inactive sources stay on existing leads.</p>

            {firstError(form.errors, 'sources') ? (
                <Alert severity="error" className="mt-3">
                    {firstError(form.errors, 'sources')}
                </Alert>
            ) : null}

            <ul className="mt-4 space-y-2">
                {rows.map((row, index) => (
                    <li key={row.id ?? `new-${index}`} className="flex flex-wrap items-center gap-2">
                        <ReorderButtons index={index} count={rows.length} disabled={!canUpdate} onMove={(i, delta) => form.setData('sources', move(rows, i, delta))} />
                        <TextField
                            size="small"
                            value={row.name}
                            disabled={!canUpdate}
                            onChange={(event) => setRow(index, { name: event.target.value })}
                            error={Boolean(form.errors[`sources.${index}.name`])}
                            helperText={form.errors[`sources.${index}.name`]}
                            slotProps={{ htmlInput: { maxLength: 60, 'aria-label': 'Source name' } }}
                            className="min-w-40 flex-1"
                        />
                        <Switch
                            checked={row.is_active}
                            disabled={!canUpdate}
                            onChange={(event) => setRow(index, { is_active: event.target.checked })}
                            slotProps={{ input: { 'aria-label': `${row.name || 'Source'} active` } }}
                        />
                    </li>
                ))}
            </ul>

            {canUpdate ? (
                <div className="mt-4 flex flex-wrap justify-between gap-2">
                    <Button startIcon={<AddIcon />} disabled={rows.length >= 30} onClick={() => form.setData('sources', [...rows, { id: null, name: '', is_active: true }])}>
                        Add source
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                        Save sources
                    </Button>
                </div>
            ) : null}
        </form>
    );
}

export default function Crm({ stages, sources, outcomes, autoAssign }) {
    const { can } = useTenant();
    const canUpdate = can('settings.update');
    const [saving, setSaving] = useState(false);

    const toggleAutoAssign = (value) => {
        router.put('/settings/crm/assignment', { auto_assign: value }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AppLayout title="CRM settings">
            <Button component={Link} href="/leads" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Leads
            </Button>
            <PageHeader
                title="CRM settings"
                description={canUpdate ? 'Your pipeline, lead sources and assignment rules.' : 'You have view-only access.'}
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <Card variant="outlined" className="lg:col-span-2">
                    <CardContent>
                        {/* Remount after a save so the editor reflects the stored order and new ids. */}
                        <StagesEditor key={JSON.stringify(stages)} stages={stages} outcomes={outcomes} canUpdate={canUpdate} />
                    </CardContent>
                </Card>

                <div className="space-y-4">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Assignment</h2>
                            <FormControlLabel
                                className="mt-2"
                                control={<Switch checked={autoAssign} disabled={!canUpdate || saving} onChange={(event) => toggleAutoAssign(event.target.checked)} />}
                                label="Auto-assign new leads"
                            />
                            <p className="text-sm text-slate-600">
                                New leads without an owner go to the team member with the fewest open leads. Only members who can work leads are
                                considered.
                            </p>
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent>
                            <SourcesEditor key={JSON.stringify(sources)} sources={sources} canUpdate={canUpdate} />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

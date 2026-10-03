import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import AdminLayout from '@/layouts/AdminLayout';
import PageHeader from '@/components/PageHeader';
import { LIMIT_ORDER, limitLabel, rupees } from '@/utils/billing';

function PlanDialog({ plan, onClose }) {
    const form = useForm({
        name: plan.name,
        description: plan.description ?? '',
        price_monthly: plan.price_monthly / 100,
        price_yearly: plan.price_yearly / 100,
        is_public: plan.is_public,
        is_active: plan.is_active,
        limits: {
            members: plan.limits.members ?? '',
            storage_mb: plan.limits.storage_mb ?? 0,
            ai_tokens: plan.limits.ai_tokens ?? 0,
            automations: plan.limits.automations ?? '',
            instagram: Boolean(plan.limits.instagram),
        },
    });
    const setLimit = (key, value) => form.setData('limits', { ...form.data.limits, [key]: value });
    const error = (key) => form.errors[key] ?? form.errors[`limits.${key}`];

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            limits: {
                ...data.limits,
                members: data.limits.members === '' ? null : Number(data.limits.members),
                automations: data.limits.automations === '' ? null : Number(data.limits.automations),
            },
        }));
        form.put(`/billing/plans/${plan.id}`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={form.processing ? undefined : onClose} fullWidth maxWidth="sm">
            <form onSubmit={submit}>
                <DialogTitle>Edit {plan.name}</DialogTitle>
                <DialogContent dividers className="space-y-3">
                    <TextField fullWidth size="small" label="Name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={Boolean(error('name'))} helperText={error('name')} />
                    <TextField fullWidth size="small" label="Description" value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} slotProps={{ htmlInput: { maxLength: 300 } }} />
                    {!plan.is_trial ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <TextField type="number" size="small" label="Monthly price (₹)" value={form.data.price_monthly} onChange={(event) => form.setData('price_monthly', event.target.value)} error={Boolean(error('price_monthly'))} helperText={error('price_monthly')} slotProps={{ htmlInput: { min: 0, step: '1' } }} />
                            <TextField type="number" size="small" label="Yearly price (₹)" value={form.data.price_yearly} onChange={(event) => form.setData('price_yearly', event.target.value)} error={Boolean(error('price_yearly'))} helperText={error('price_yearly')} slotProps={{ htmlInput: { min: 0, step: '1' } }} />
                        </div>
                    ) : null}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField type="number" size="small" label="Team members" value={form.data.limits.members} onChange={(event) => setLimit('members', event.target.value)} error={Boolean(error('members'))} helperText={error('members') ?? 'Empty = unlimited'} />
                        <TextField type="number" size="small" label="Active automations" value={form.data.limits.automations} onChange={(event) => setLimit('automations', event.target.value)} error={Boolean(error('automations'))} helperText={error('automations') ?? 'Empty = unlimited'} />
                        <TextField type="number" size="small" label="Storage (MB)" value={form.data.limits.storage_mb} onChange={(event) => setLimit('storage_mb', event.target.value)} error={Boolean(error('storage_mb'))} helperText={error('storage_mb') ?? '1024 MB = 1 GB'} />
                        <TextField type="number" size="small" label="AI tokens a month" value={form.data.limits.ai_tokens} onChange={(event) => setLimit('ai_tokens', event.target.value)} error={Boolean(error('ai_tokens'))} helperText={error('ai_tokens')} />
                    </div>
                    <div className="flex flex-wrap gap-x-6">
                        <FormControlLabel control={<Switch checked={form.data.limits.instagram} onChange={(event) => setLimit('instagram', event.target.checked)} />} label="Instagram inbox" />
                        {!plan.is_trial ? <FormControlLabel control={<Switch checked={form.data.is_public} onChange={(event) => form.setData('is_public', event.target.checked)} />} label="Businesses can choose it" /> : null}
                        <FormControlLabel control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />} label="Active" />
                    </div>
                    <p className="text-xs text-slate-500">New prices apply to the next payment. Limits apply straight away to every business on this plan.</p>
                </DialogContent>
                <DialogActions>
                    <Button color="inherit" onClick={onClose} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        Save
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

export default function Plans({ plans }) {
    const [editing, setEditing] = useState(null);

    return (
        <AdminLayout title="Plans">
            <PageHeader title="Plans" description="What businesses pay and what each plan includes. Plans are hidden, never deleted." />

            <div className="grid gap-4 md:grid-cols-2">
                {plans.map((plan) => (
                    <Card key={plan.id} variant="outlined">
                        <CardContent>
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-semibold text-slate-900">{plan.name}</h2>
                                {plan.is_trial ? <Chip size="small" label="Free trial" color="info" variant="outlined" /> : null}
                                {!plan.is_trial && !plan.is_public ? <Chip size="small" label="Hidden" /> : null}
                                {!plan.is_active ? <Chip size="small" label="Inactive" color="error" variant="outlined" /> : null}
                                <span className="ml-auto text-xs text-slate-500">{plan.subscribers} businesses</span>
                            </div>
                            {plan.description ? <p className="mt-1 text-sm text-slate-600">{plan.description}</p> : null}
                            {!plan.is_trial ? (
                                <p className="mt-2 text-sm text-slate-900">
                                    {rupees(plan.price_monthly)}/month · {rupees(plan.price_yearly)}/year
                                </p>
                            ) : null}
                            <ul className="mt-2 text-sm text-slate-700">
                                {LIMIT_ORDER.map((key) => (
                                    <li key={key}>{limitLabel(key, plan.limits[key])}</li>
                                ))}
                            </ul>
                            <Button size="small" sx={{ mt: 1 }} onClick={() => setEditing(plan)}>
                                Edit
                            </Button>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {editing ? <PlanDialog plan={editing} onClose={() => setEditing(null)} /> : null}
        </AdminLayout>
    );
}

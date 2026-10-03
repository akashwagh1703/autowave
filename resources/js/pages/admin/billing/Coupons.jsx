import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import CouponIcon from '@mui/icons-material/LocalOfferOutlined';
import AdminLayout from '@/layouts/AdminLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import EmptyState from '@/components/EmptyState';
import PageHeader from '@/components/PageHeader';
import { formatDate } from '@/utils/format';
import { rupees } from '@/utils/billing';

const BLANK = {
    code: '',
    description: '',
    type: 'percent',
    value: '',
    plans: [],
    periods: [],
    max_redemptions: '',
    once_per_business: true,
    first_payment_only: false,
    starts_on: '',
    ends_on: '',
    is_active: true,
};

function discountLabel(coupon) {
    return coupon.type === 'percent' ? `${coupon.value}% off` : `${rupees(coupon.value)} off`;
}

function CouponDialog({ coupon, plans, periods, onClose }) {
    const form = useForm(
        coupon
            ? {
                  ...BLANK,
                  ...coupon,
                  description: coupon.description ?? '',
                  value: coupon.type === 'percent' ? coupon.value : coupon.value / 100,
                  max_redemptions: coupon.max_redemptions ?? '',
                  starts_on: coupon.starts_on ?? '',
                  ends_on: coupon.ends_on ?? '',
              }
            : BLANK,
    );
    const field = (key) => ({
        value: form.data[key],
        onChange: (event) => form.setData(key, event.target.value),
        error: Boolean(form.errors[key]),
        helperText: form.errors[key],
    });
    const listError = (key) => form.errors[key] ?? Object.entries(form.errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            max_redemptions: data.max_redemptions === '' ? null : Number(data.max_redemptions),
            starts_on: data.starts_on || null,
            ends_on: data.ends_on || null,
        }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (coupon) {
            form.put(`/billing/coupons/${coupon.id}`, options);
        } else {
            form.post('/billing/coupons', options);
        }
    };

    return (
        <Dialog open onClose={form.processing ? undefined : onClose} fullWidth maxWidth="sm">
            <form onSubmit={submit}>
                <DialogTitle>{coupon ? `Edit ${coupon.code}` : 'New coupon'}</DialogTitle>
                <DialogContent dividers className="space-y-3">
                    <TextField
                        fullWidth
                        size="small"
                        label="Code"
                        {...field('code')}
                        onChange={(event) => form.setData('code', event.target.value.toUpperCase())}
                        helperText={form.errors.code ?? 'What owners type, e.g. DIWALI25. Letters, numbers and dashes.'}
                        slotProps={{ htmlInput: { maxLength: 30 } }}
                    />
                    <TextField fullWidth size="small" label="Description (only you see this)" {...field('description')} slotProps={{ htmlInput: { maxLength: 255 } }} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField select size="small" label="Discount type" {...field('type')}>
                            <MenuItem value="percent">Percentage</MenuItem>
                            <MenuItem value="fixed">Fixed amount (₹)</MenuItem>
                        </TextField>
                        <TextField
                            type="number"
                            size="small"
                            label={form.data.type === 'percent' ? 'Percent off' : 'Rupees off'}
                            {...field('value')}
                            helperText={form.errors.value ?? (form.data.type === 'percent' ? '100 makes the plan free' : 'Taken off before GST')}
                            slotProps={{ htmlInput: { min: 1, max: form.data.type === 'percent' ? 100 : 100000, step: form.data.type === 'percent' ? 1 : 'any' } }}
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField
                            select
                            size="small"
                            label="Plans"
                            value={form.data.plans}
                            onChange={(event) => form.setData('plans', event.target.value)}
                            error={Boolean(listError('plans'))}
                            helperText={listError('plans') ?? 'None selected = every plan'}
                            slotProps={{ select: { multiple: true, renderValue: (selected) => selected.map((code) => plans.find((plan) => plan.value === code)?.label ?? code).join(', ') } }}
                        >
                            {plans.map((plan) => (
                                <MenuItem key={plan.value} value={plan.value}>
                                    {plan.label}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField
                            select
                            size="small"
                            label="Billing periods"
                            value={form.data.periods}
                            onChange={(event) => form.setData('periods', event.target.value)}
                            error={Boolean(listError('periods'))}
                            helperText={listError('periods') ?? 'None selected = monthly and yearly'}
                            slotProps={{ select: { multiple: true, renderValue: (selected) => selected.map((key) => periods.find((period) => period.value === key)?.label ?? key).join(', ') } }}
                        >
                            {periods.map((period) => (
                                <MenuItem key={period.value} value={period.value}>
                                    {period.label}
                                </MenuItem>
                            ))}
                        </TextField>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <TextField type="number" size="small" label="Total uses" {...field('max_redemptions')} helperText={form.errors.max_redemptions ?? 'Empty = no limit'} slotProps={{ htmlInput: { min: 1 } }} />
                        <TextField type="date" size="small" label="Starts on" {...field('starts_on')} slotProps={{ inputLabel: { shrink: true } }} />
                        <TextField type="date" size="small" label="Ends on" {...field('ends_on')} helperText={form.errors.ends_on ?? 'Last day it works'} slotProps={{ inputLabel: { shrink: true } }} />
                    </div>
                    <div className="flex flex-wrap gap-x-6">
                        <FormControlLabel control={<Switch checked={form.data.once_per_business} onChange={(event) => form.setData('once_per_business', event.target.checked)} />} label="Once per business" />
                        <FormControlLabel control={<Switch checked={form.data.first_payment_only} onChange={(event) => form.setData('first_payment_only', event.target.checked)} />} label="New customers only" />
                        <FormControlLabel control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />} label="Active" />
                    </div>
                    <p className="text-xs text-slate-500">Dates are India time. The discount comes off the plan price after any credit from the current plan, before GST.</p>
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

export default function Coupons({ coupons, plans, periods }) {
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [processing, setProcessing] = useState(false);
    const planName = (code) => plans.find((plan) => plan.value === code)?.label ?? code;
    const periodName = (key) => periods.find((period) => period.value === key)?.label ?? key;

    const remove = () =>
        router.delete(`/billing/coupons/${deleting.id}`, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });

    const newButton = (
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing('new')}>
            New coupon
        </Button>
    );

    return (
        <AdminLayout title="Coupons">
            <PageHeader title="Coupons" description="Discount codes owners can enter when they pay. A coupon that has been used can be switched off but not deleted." actions={newButton} />

            {coupons.length === 0 ? (
                <EmptyState icon={CouponIcon} title="No coupons yet" description="Create a code like LAUNCH50 to give businesses a discount." action={newButton} />
            ) : (
                <Card variant="outlined">
                    <div className="overflow-x-auto">
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Code</TableCell>
                                    <TableCell>Discount</TableCell>
                                    <TableCell>Applies to</TableCell>
                                    <TableCell>Valid</TableCell>
                                    <TableCell align="right">Used</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell align="right">Actions</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {coupons.map((coupon) => (
                                    <TableRow key={coupon.id}>
                                        <TableCell>
                                            <span className="font-mono font-semibold text-slate-900">{coupon.code}</span>
                                            {coupon.description ? <span className="block text-xs text-slate-500">{coupon.description}</span> : null}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">{discountLabel(coupon)}</TableCell>
                                        <TableCell>
                                            {coupon.plans.length ? coupon.plans.map(planName).join(', ') : 'All plans'}
                                            <span className="block text-xs text-slate-500">{coupon.periods.length ? coupon.periods.map(periodName).join(', ') : 'Monthly and yearly'}</span>
                                            {coupon.first_payment_only ? <span className="block text-xs text-slate-500">New customers only</span> : null}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {coupon.starts_on ? `From ${formatDate(coupon.starts_on, 'UTC')}` : 'From now'}
                                            <span className="block text-xs text-slate-500">{coupon.ends_on ? `until ${formatDate(coupon.ends_on, 'UTC')}` : 'no end date'}</span>
                                        </TableCell>
                                        <TableCell align="right" className="whitespace-nowrap">
                                            {coupon.redemptions}
                                            {coupon.max_redemptions ? ` / ${coupon.max_redemptions}` : ''}
                                        </TableCell>
                                        <TableCell>
                                            {coupon.live ? <Chip size="small" variant="outlined" color="success" label="Live" /> : <Chip size="small" variant="outlined" label={coupon.is_active ? 'Not in date' : 'Off'} />}
                                        </TableCell>
                                        <TableCell align="right" className="whitespace-nowrap">
                                            {coupon.redemptions === 0 ? (
                                                <Button size="small" color="error" onClick={() => setDeleting(coupon)}>
                                                    Delete
                                                </Button>
                                            ) : null}
                                            <Button size="small" onClick={() => setEditing(coupon)}>
                                                Edit
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Card>
            )}

            {editing ? <CouponDialog coupon={editing === 'new' ? null : editing} plans={plans} periods={periods} onClose={() => setEditing(null)} /> : null}

            <ConfirmDialog
                open={Boolean(deleting)}
                title={`Delete coupon ${deleting?.code ?? ''}?`}
                description="Nobody has used it yet, so it can be removed completely."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={remove}
                onClose={() => setDeleting(null)}
            />
        </AdminLayout>
    );
}

import { router, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditIcon from '@mui/icons-material/Edit';
import LocalOfferIcon from '@mui/icons-material/LocalOfferOutlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import ConfirmDialog from '@/components/ConfirmDialog';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatPrice, toLocalInput } from '@/utils/format';

function describe(coupon, currency) {
    return coupon.type === 'percent' ? `${Number(coupon.value)}% off` : `${formatPrice(coupon.value, currency)} off`;
}

function CouponDialog({ coupon, open, onClose }) {
    const { currency, timezone } = useTenant();
    const form = useForm({
        code: coupon?.code ?? '',
        description: coupon?.description ?? '',
        type: coupon?.type ?? 'percent',
        value: coupon?.value ?? '',
        min_subtotal: coupon?.min_subtotal ?? '',
        max_discount: coupon?.max_discount ?? '',
        starts_at: toLocalInput(coupon?.starts_at, timezone),
        ends_at: toLocalInput(coupon?.ends_at, timezone),
        usage_limit: coupon?.usage_limit ?? '',
        online: coupon?.online ?? true,
        is_active: coupon?.is_active ?? true,
    });
    const money = { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> };

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (coupon) {
            form.put(`/offers/${coupon.id}`, options);
        } else {
            form.post('/offers', options);
        }
    };

    const field = (name) => ({
        value: form.data[name] ?? '',
        onChange: (event) => form.setData(name, event.target.value),
        error: Boolean(form.errors[name]),
    });

    return (
        <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>{coupon ? `Edit ${coupon.code}` : 'New coupon'}</DialogTitle>
                <DialogContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label="Code"
                            required
                            autoFocus
                            {...field('code')}
                            onChange={(event) => form.setData('code', event.target.value.toUpperCase().replace(/\s+/g, ''))}
                            helperText={form.errors.code ?? 'e.g. DIWALI10. Letters, numbers, - and _.'}
                            slotProps={{ htmlInput: { maxLength: 30 } }}
                            className="mt-1"
                        />
                        <TextField label="Description" {...field('description')} helperText={form.errors.description ?? 'Shown at checkout.'} slotProps={{ htmlInput: { maxLength: 150 } }} className="mt-1" />
                    </div>
                    <div className="flex flex-wrap items-start gap-4">
                        <ToggleButtonGroup exclusive size="small" value={form.data.type} onChange={(_, value) => value && form.setData('type', value)} aria-label="Discount type" className="mt-1">
                            <ToggleButton value="percent">% off</ToggleButton>
                            <ToggleButton value="fixed">Amount off</ToggleButton>
                        </ToggleButtonGroup>
                        <TextField
                            label={form.data.type === 'percent' ? 'Percent' : 'Amount'}
                            type="number"
                            required
                            {...field('value')}
                            helperText={form.errors.value}
                            slotProps={{
                                htmlInput: { min: 0.01, max: form.data.type === 'percent' ? 100 : undefined, step: '0.01' },
                                input: form.data.type === 'percent' ? { endAdornment: <InputAdornment position="end">%</InputAdornment> } : money,
                            }}
                            className="w-40"
                        />
                        {form.data.type === 'percent' ? (
                            <TextField label="Up to" type="number" {...field('max_discount')} helperText={form.errors.max_discount ?? 'Optional cap.'} slotProps={{ htmlInput: { min: 0.01, step: '0.01' }, input: money }} className="w-40" />
                        ) : null}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField label="Minimum order" type="number" {...field('min_subtotal')} helperText={form.errors.min_subtotal ?? 'Optional, before discount.'} slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: money }} />
                        <TextField label="Total uses allowed" type="number" {...field('usage_limit')} helperText={form.errors.usage_limit ?? 'Optional. Leave empty for unlimited.'} slotProps={{ htmlInput: { min: 1 } }} />
                        <TextField label="Starts" type="datetime-local" {...field('starts_at')} helperText={form.errors.starts_at ?? 'Optional.'} slotProps={{ inputLabel: { shrink: true } }} />
                        <TextField label="Ends" type="datetime-local" {...field('ends_at')} helperText={form.errors.ends_at ?? 'Optional.'} slotProps={{ inputLabel: { shrink: true } }} />
                    </div>
                    <div className="flex flex-wrap gap-4">
                        <FormControlLabel control={<Switch checked={form.data.online} onChange={(event) => form.setData('online', event.target.checked)} />} label="Customers can use it on the website" />
                        <FormControlLabel control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />} label="Active" />
                    </div>
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        {coupon ? 'Save' : 'Create coupon'}
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

export default function Index({ coupons }) {
    const { can, currency, timezone } = useTenant();
    const canManage = can('offers.manage');
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [processing, setProcessing] = useState(false);
    const live = coupons.filter((coupon) => coupon.is_live).length;

    const destroy = () =>
        router.delete(`/offers/${deleting.id}`, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });

    const statusChip = (coupon) => {
        if (!coupon.is_active) {
            return <Chip label="Off" size="small" variant="outlined" />;
        }

        if (coupon.is_live) {
            return <Chip label="Live" size="small" color="success" variant="outlined" />;
        }

        if (coupon.usage_limit !== null && coupon.times_used >= coupon.usage_limit) {
            return <Chip label="Used up" size="small" variant="outlined" />;
        }

        return <Chip label={coupon.ends_at && new Date(coupon.ends_at) < new Date() ? 'Ended' : 'Scheduled'} size="small" variant="outlined" />;
    };

    return (
        <AppLayout title="Offers">
            <PageHeader
                title="Offers"
                description={`Coupon codes for orders · ${live} live`}
                actions={
                    canManage ? (
                        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing({})}>
                            New coupon
                        </Button>
                    ) : null
                }
            />

            {coupons.length === 0 ? (
                <EmptyState
                    icon={LocalOfferIcon}
                    title="No coupons yet"
                    description="Create a code like WELCOME10 for a percentage or amount off. Customers enter it at checkout, or your team applies it to an order."
                    action={
                        canManage ? (
                            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing({})}>
                                New coupon
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <Card variant="outlined">
                    <TableContainer>
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Code</TableCell>
                                    <TableCell>Discount</TableCell>
                                    <TableCell className="hidden md:table-cell">Valid</TableCell>
                                    <TableCell align="right">Used</TableCell>
                                    <TableCell align="right" className="hidden md:table-cell">
                                        Discount given
                                    </TableCell>
                                    <TableCell>Status</TableCell>
                                    {canManage ? <TableCell /> : null}
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {coupons.map((coupon) => (
                                    <TableRow key={coupon.id} hover>
                                        <TableCell>
                                            <span className="font-mono font-semibold text-slate-900">{coupon.code}</span>
                                            {coupon.description ? <p className="text-xs text-slate-500">{coupon.description}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            {describe(coupon, currency)}
                                            <p className="text-xs text-slate-500">
                                                {[
                                                    coupon.max_discount ? `up to ${formatPrice(coupon.max_discount, currency)}` : null,
                                                    coupon.min_subtotal ? `min ${formatPrice(coupon.min_subtotal, currency)}` : null,
                                                    coupon.online ? null : 'staff only',
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </TableCell>
                                        <TableCell className="hidden text-sm md:table-cell">
                                            {coupon.starts_at || coupon.ends_at
                                                ? `${coupon.starts_at ? formatDateTime(coupon.starts_at, timezone, { day: 'numeric', month: 'short' }) : 'Now'} – ${coupon.ends_at ? formatDateTime(coupon.ends_at, timezone, { day: 'numeric', month: 'short' }) : 'no end'}`
                                                : 'Always'}
                                        </TableCell>
                                        <TableCell align="right">
                                            {coupon.times_used}
                                            {coupon.usage_limit ? ` / ${coupon.usage_limit}` : ''}
                                        </TableCell>
                                        <TableCell align="right" className="hidden md:table-cell">
                                            {formatPrice(coupon.discount_given, currency)}
                                        </TableCell>
                                        <TableCell>{statusChip(coupon)}</TableCell>
                                        {canManage ? (
                                            <TableCell align="right" className="whitespace-nowrap">
                                                <IconButton size="small" aria-label={`Edit ${coupon.code}`} onClick={() => setEditing(coupon)}>
                                                    <EditIcon fontSize="small" />
                                                </IconButton>
                                                <IconButton size="small" aria-label={`Delete ${coupon.code}`} onClick={() => setDeleting(coupon)}>
                                                    <DeleteOutlineIcon fontSize="small" />
                                                </IconButton>
                                            </TableCell>
                                        ) : null}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                </Card>
            )}

            {editing ? <CouponDialog key={editing.id ?? 'new'} coupon={editing.id ? editing : null} open onClose={() => setEditing(null)} /> : null}

            <ConfirmDialog
                open={deleting !== null}
                title={`Delete ${deleting?.code ?? 'coupon'}?`}
                description="The code stops working. Orders that used it keep the code and discount."
                confirmLabel="Delete coupon"
                destructive
                processing={processing}
                onConfirm={destroy}
                onClose={() => setDeleting(null)}
            />
        </AppLayout>
    );
}

import { Link, router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditIcon from '@mui/icons-material/Edit';
import TableRestaurantIcon from '@mui/icons-material/TableRestaurantOutlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import ConfirmDialog from '@/components/ConfirmDialog';
import useTenant from '@/hooks/useTenant';
import { formatTime } from '@/utils/booking';
import { formatPrice } from '@/utils/format';

function TableDialog({ table, open, onClose }) {
    const form = useForm({ name: table?.name ?? '', seats: table?.seats ?? 4, area: table?.area ?? '', is_active: table?.is_active ?? true });

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (table) {
            form.put(`/tables/${table.id}`, options);
        } else {
            form.post('/tables', options);
        }
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>{table ? 'Edit table' : 'Add table'}</DialogTitle>
                <DialogContent className="space-y-4">
                    <TextField
                        label="Name"
                        required
                        fullWidth
                        autoFocus
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={Boolean(form.errors.name)}
                        helperText={form.errors.name ?? 'e.g. T1, Window 2'}
                        slotProps={{ htmlInput: { maxLength: 40 } }}
                        className="mt-1"
                    />
                    <div className="grid grid-cols-2 gap-4">
                        <TextField
                            label="Seats"
                            type="number"
                            required
                            value={form.data.seats}
                            onChange={(event) => form.setData('seats', event.target.value)}
                            error={Boolean(form.errors.seats)}
                            helperText={form.errors.seats}
                            slotProps={{ htmlInput: { min: 1, max: 100 } }}
                        />
                        <TextField
                            label="Area"
                            value={form.data.area ?? ''}
                            onChange={(event) => form.setData('area', event.target.value)}
                            error={Boolean(form.errors.area)}
                            helperText={form.errors.area ?? 'e.g. Terrace'}
                            slotProps={{ htmlInput: { maxLength: 40 } }}
                        />
                    </div>
                    <FormControlLabel
                        control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />}
                        label="In use"
                    />
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        {table ? 'Save' : 'Add table'}
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

function TableCard({ table, canManage, canOrder, onEdit, onDelete }) {
    const { currency, timezone } = useTenant();
    const occupied = table.orders.length > 0;
    const next = table.next_reservation;

    return (
        <Card variant="outlined" className={occupied ? 'border-amber-300 bg-amber-50/40' : !table.is_active ? 'opacity-60' : ''}>
            <CardContent className="space-y-2">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">{table.name}</h2>
                        <p className="text-xs text-slate-500">
                            {table.seats} seats{table.area ? ` · ${table.area}` : ''}
                        </p>
                    </div>
                    {!table.is_active ? (
                        <Chip label="Not in use" size="small" variant="outlined" />
                    ) : occupied ? (
                        <Chip label="Occupied" size="small" color="warning" />
                    ) : next?.status === 'seated' ? (
                        <Chip label="Seated" size="small" color="warning" />
                    ) : (
                        <Chip label="Free" size="small" color="success" variant="outlined" />
                    )}
                </div>

                {table.orders.map((order) => (
                    <Link key={order.id} href={`/orders/${order.id}`} className="block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm hover:border-brand-300">
                        <span className="flex justify-between gap-2">
                            <span className="font-medium text-slate-900">{order.reference}</span>
                            <span className="text-slate-700">{formatPrice(order.total, currency)}</span>
                        </span>
                        <span className="block truncate text-xs text-slate-500">{order.item_summary}</span>
                        <span className="block text-xs text-slate-500">
                            {order.status_label}
                            {order.queued ? ` · ${order.queued} in kitchen` : ''}
                            {Number(order.balance) > 0 ? ` · ${formatPrice(order.balance, currency)} to pay` : ' · paid'}
                        </span>
                    </Link>
                ))}

                {next ? (
                    <Link href={`/reservations/${next.id}`} className="block text-xs text-slate-600 hover:text-brand-700">
                        {next.status === 'seated' ? 'Seated' : 'Reserved'} {formatTime(next.reserved_at, timezone)}–{formatTime(next.ends_at, timezone)} · {next.customer?.name} ({next.party_size})
                    </Link>
                ) : null}

                <div className="flex items-center justify-between gap-1 pt-1">
                    {canOrder && table.is_active ? (
                        <Button size="small" component={Link} href={`/orders/create?table=${table.id}`} startIcon={<AddIcon />}>
                            {occupied ? 'Another order' : 'New order'}
                        </Button>
                    ) : (
                        <span />
                    )}
                    {canManage ? (
                        <span>
                            <IconButton size="small" aria-label={`Edit ${table.name}`} onClick={() => onEdit(table)}>
                                <EditIcon fontSize="small" />
                            </IconButton>
                            <IconButton size="small" aria-label={`Delete ${table.name}`} onClick={() => onDelete(table)}>
                                <DeleteOutlineIcon fontSize="small" />
                            </IconButton>
                        </span>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

export default function Index({ tables }) {
    const { can, hasEngine } = useTenant();
    const { errors } = usePage().props;
    const canManage = can('reservations.manage');
    const canOrder = hasEngine('commerce') && can('orders.create');
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [processing, setProcessing] = useState(false);
    const occupied = tables.filter((table) => table.orders.length > 0).length;
    const areas = [...new Set(tables.map((table) => table.area || ''))];

    const destroy = () =>
        router.delete(`/tables/${deleting.id}`, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });

    return (
        <AppLayout title="Tables">
            <PageHeader
                title="Tables"
                description={tables.length ? `${occupied} of ${tables.length} occupied` : 'Your dining floor'}
                actions={
                    <>
                        {can('reservations.view') ? (
                            <Button component={Link} href="/reservations" color="inherit">
                                Reservations
                            </Button>
                        ) : null}
                        {canManage ? (
                            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing({})}>
                                Add table
                            </Button>
                        ) : null}
                    </>
                }
            />

            {errors.table ? (
                <Alert severity="error" className="mb-4">
                    {errors.table}
                </Alert>
            ) : null}

            {tables.length === 0 ? (
                <EmptyState
                    icon={TableRestaurantIcon}
                    title="No tables yet"
                    description="Add your tables to take reservations and dine-in orders by table."
                    action={
                        canManage ? (
                            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing({})}>
                                Add table
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <div className="space-y-6">
                    {areas.map((area) => (
                        <section key={area || 'none'}>
                            {areas.length > 1 ? <h2 className="mb-2 text-sm font-semibold tracking-wide text-slate-500 uppercase">{area || 'Other'}</h2> : null}
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {tables
                                    .filter((table) => (table.area || '') === area)
                                    .map((table) => (
                                        <TableCard key={table.id} table={table} canManage={canManage} canOrder={canOrder} onEdit={setEditing} onDelete={setDeleting} />
                                    ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            {editing ? <TableDialog key={editing.id ?? 'new'} table={editing.id ? editing : null} open onClose={() => setEditing(null)} /> : null}

            <ConfirmDialog
                open={deleting !== null}
                title={`Delete ${deleting?.name ?? 'table'}?`}
                description="Tables with open orders or upcoming reservations can’t be deleted. Past orders keep the table name."
                confirmLabel="Delete table"
                destructive
                processing={processing}
                onConfirm={destroy}
                onClose={() => setDeleting(null)}
            />
        </AppLayout>
    );
}

import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import { useState } from 'react';
import useTenant from '@/hooks/useTenant';
import { formatDateTime } from '@/utils/format';

const MODES = [
    { value: 'add', label: 'Add', help: 'New stock received or found.' },
    { value: 'remove', label: 'Remove', help: 'Damaged, used or lost items.' },
    { value: 'set', label: 'Set count', help: 'After counting what is on the shelf.' },
];

function AdjustDialog({ product, reasons, open, onClose }) {
    const form = useForm({ mode: 'add', quantity: '', reason: reasons[0]?.value ?? '', note: '' });
    const mode = MODES.find((item) => item.value === form.data.mode);

    const changeMode = (value) => {
        if (!value) {
            return;
        }

        form.setData((data) => ({
            ...data,
            mode: value,
            reason: value === 'add' ? 'restock' : value === 'set' ? 'adjustment' : 'damaged',
        }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(`/products/${product.id}/stock`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>Change stock</DialogTitle>
                <DialogContent className="space-y-4">
                    <p className="text-sm text-slate-600">
                        {product.name} has <strong>{product.stock_quantity}</strong> in stock.
                    </p>
                    <ToggleButtonGroup exclusive fullWidth size="small" value={form.data.mode} onChange={(_, value) => changeMode(value)} aria-label="How to change the stock">
                        {MODES.map((item) => (
                            <ToggleButton key={item.value} value={item.value}>
                                {item.label}
                            </ToggleButton>
                        ))}
                    </ToggleButtonGroup>
                    <TextField
                        label={form.data.mode === 'set' ? 'New count' : 'Quantity'}
                        type="number"
                        autoFocus
                        required
                        fullWidth
                        value={form.data.quantity}
                        onChange={(event) => form.setData('quantity', event.target.value)}
                        error={Boolean(form.errors.quantity)}
                        helperText={form.errors.quantity ?? mode?.help}
                        slotProps={{ htmlInput: { min: 0, step: 1 } }}
                    />
                    <TextField
                        select
                        label="Reason"
                        fullWidth
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                        error={Boolean(form.errors.reason)}
                        helperText={form.errors.reason}
                    >
                        {reasons.map((reason) => (
                            <MenuItem key={reason.value} value={reason.value}>
                                {reason.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField
                        label="Note"
                        fullWidth
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        error={Boolean(form.errors.note)}
                        helperText={form.errors.note ?? 'Optional, e.g. the supplier invoice number.'}
                        slotProps={{ htmlInput: { maxLength: 255 } }}
                    />
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing || form.data.quantity === ''}>
                        Save
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

/** Current stock, the adjust dialog and the latest stock movements of a tracked product. */
export default function StockCard({ product, movements, reasons }) {
    const { timezone } = useTenant();
    const [adjusting, setAdjusting] = useState(false);

    return (
        <Card variant="outlined">
            <CardContent>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="font-semibold text-slate-900">Stock</h2>
                        <p className="mt-1 text-3xl font-semibold text-slate-900">{product.stock_quantity}</p>
                        <div className="mt-1">
                            {product.stock_quantity === 0 ? (
                                <Chip size="small" color="error" variant="outlined" label="Out of stock" />
                            ) : product.is_low_stock ? (
                                <Chip size="small" color="warning" variant="outlined" label={`Low — alert at ${product.low_stock_level}`} />
                            ) : (
                                <span className="text-sm text-slate-500">Alert at {product.low_stock_level} or fewer</span>
                            )}
                        </div>
                    </div>
                    <Button variant="outlined" onClick={() => setAdjusting(true)}>
                        Change stock
                    </Button>
                </div>

                <h3 className="mt-6 text-sm font-semibold text-slate-900">History</h3>
                {movements.length === 0 ? (
                    <p className="mt-1 text-sm text-slate-500">No stock changes yet.</p>
                ) : (
                    <ul className="mt-2 divide-y divide-slate-100">
                        {movements.map((movement) => (
                            <li key={movement.id} className="flex items-start justify-between gap-3 py-2 text-sm">
                                <div className="min-w-0">
                                    <p className="text-slate-900">
                                        {movement.reason_label}
                                        {movement.order ? (
                                            <>
                                                {' · '}
                                                <Link href={`/orders/${movement.order.id}`} className="text-brand-700 hover:underline">
                                                    Order {movement.order.reference ?? ''}
                                                </Link>
                                            </>
                                        ) : null}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {formatDateTime(movement.created_at, timezone)}
                                        {movement.user ? ` · ${movement.user.name}` : ''}
                                        {movement.note ? ` · ${movement.note}` : ''}
                                    </p>
                                </div>
                                <div className="shrink-0 text-right">
                                    <p className={`font-semibold ${movement.quantity_change > 0 ? 'text-green-700' : 'text-red-700'}`}>
                                        {movement.quantity_change > 0 ? `+${movement.quantity_change}` : movement.quantity_change}
                                    </p>
                                    <p className="text-xs text-slate-500">{movement.balance_after} left</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
            <AdjustDialog product={product} reasons={reasons} open={adjusting} onClose={() => setAdjusting(false)} />
        </Card>
    );
}

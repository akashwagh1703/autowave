import { useForm } from '@inertiajs/react';
import Autocomplete from '@mui/material/Autocomplete';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import TextField from '@mui/material/TextField';
import CloseIcon from '@mui/icons-material/Close';
import { useState } from 'react';
import FoodTypeMark from '@/modules/products/FoodTypeMark';
import useTenant from '@/hooks/useTenant';
import { formatPrice } from '@/utils/format';

/** More items for an open dine-in order; they go to the kitchen as a new ticket. */
export default function AddItemsDialog({ order, products, open, onClose }) {
    const { currency } = useTenant();
    const [adding, setAdding] = useState(null);
    const form = useForm({ items: [] });
    const byId = Object.fromEntries(products.map((product) => [product.id, product]));
    const total = form.data.items.reduce((sum, item) => sum + Number(byId[item.product_id]?.price ?? 0) * (Number(item.quantity) || 0), 0);
    const itemsError = form.errors.items ?? Object.entries(form.errors).find(([key]) => key.startsWith('items.'))?.[1];

    const add = (product) => {
        setAdding(null);

        if (!product) {
            return;
        }

        const existing = form.data.items.find((item) => item.product_id === product.id);
        form.setData(
            'items',
            existing
                ? form.data.items.map((item) => (item.product_id === product.id ? { ...item, quantity: Number(item.quantity) + 1 } : item))
                : [...form.data.items, { product_id: product.id, quantity: 1 }],
        );
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ items: data.items.map((item) => ({ product_id: item.product_id, quantity: Number(item.quantity) || 1 })) }));
        form.post(`/orders/${order.id}/items`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>Add items{order.table ? ` · ${order.table.name}` : ''}</DialogTitle>
                <DialogContent className="space-y-3">
                    <Autocomplete
                        value={adding}
                        onChange={(_, product) => add(product)}
                        options={products}
                        groupBy={(product) => product.category ?? 'Other'}
                        getOptionLabel={(product) => product.name}
                        getOptionDisabled={(product) => product.is_available === false || (product.track_stock && product.available === 0)}
                        renderOption={(props, product) => {
                            const { key, ...optionProps } = props;

                            return (
                                <li key={key} {...optionProps}>
                                    <span className="flex w-full items-center justify-between gap-3">
                                        <span className="flex items-center gap-2 text-sm">
                                            <FoodTypeMark type={product.food_type} />
                                            {product.name}
                                        </span>
                                        <span className="text-sm">{product.is_available === false ? <span className="text-xs text-red-600">Not available</span> : formatPrice(product.price, currency)}</span>
                                    </span>
                                </li>
                            );
                        }}
                        renderInput={(params) => <TextField {...params} label="Add a menu item" placeholder="Search" autoFocus className="mt-1" />}
                    />
                    {itemsError ? <p className="text-sm text-red-600">{itemsError}</p> : null}
                    <ul className="divide-y divide-slate-100">
                        {form.data.items.map((item) => (
                            <li key={item.product_id} className="flex items-center gap-3 py-2">
                                <span className="flex-1 text-sm text-slate-900">{byId[item.product_id]?.name}</span>
                                <TextField
                                    size="small"
                                    type="number"
                                    label="Qty"
                                    value={item.quantity}
                                    onChange={(event) => form.setData('items', form.data.items.map((row) => (row.product_id === item.product_id ? { ...row, quantity: event.target.value } : row)))}
                                    slotProps={{ htmlInput: { min: 1 } }}
                                    className="w-20"
                                />
                                <IconButton size="small" aria-label="Remove" onClick={() => form.setData('items', form.data.items.filter((row) => row.product_id !== item.product_id))}>
                                    <CloseIcon fontSize="small" />
                                </IconButton>
                            </li>
                        ))}
                    </ul>
                    {form.data.items.length ? <p className="text-right text-sm font-medium text-slate-900">Adds {formatPrice(total.toFixed(2), currency)}</p> : null}
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing || form.data.items.length === 0}>
                        Add to order
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import CloseIcon from '@mui/icons-material/Close';
import { useEffect, useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import CustomerPicker from '@/modules/booking/CustomerPicker';
import FoodTypeMark from '@/modules/products/FoodTypeMark';
import useTenant from '@/hooks/useTenant';
import { formatPrice } from '@/utils/format';
import { errorMessage, postJson } from '@/utils/http';

// Money in paise/cents, so the running total never drifts. The server re-prices on save.
const cents = (value) => Math.round((Number(value) || 0) * 100);
const fromCents = (value) => (value / 100).toFixed(2);

function Section({ step, title, children }) {
    return (
        <section className="space-y-3">
            <h2 className="flex items-center gap-2 font-semibold text-slate-900">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-brand-100 text-xs text-brand-700">{step}</span>
                {title}
            </h2>
            {children}
        </section>
    );
}

function CouponField({ items, applied, onApplied }) {
    const [code, setCode] = useState(applied?.code ?? '');
    const [error, setError] = useState(null);
    const [checking, setChecking] = useState(false);
    const itemsKey = JSON.stringify(items);

    const check = (value) => {
        if (!value.trim()) {
            onApplied(null);

            return;
        }

        setChecking(true);
        setError(null);
        postJson('/orders/coupon', { code: value.trim(), items })
            .then((data) => onApplied(data))
            .catch((failure) => {
                onApplied(null);
                setError(errorMessage(failure, 'This code can’t be used.'));
            })
            .finally(() => setChecking(false));
    };

    useEffect(() => {
        if (applied?.code && items.length) {
            check(applied.code);
        }
    }, [itemsKey]);

    return (
        <div>
            <div className="flex items-start gap-2">
                <TextField
                    size="small"
                    label="Coupon code"
                    value={code}
                    onChange={(event) => setCode(event.target.value.toUpperCase().replace(/\s+/g, ''))}
                    error={Boolean(error)}
                    helperText={error ?? (applied ? `${applied.summary} applied` : null)}
                    slotProps={{ htmlInput: { maxLength: 30 } }}
                    className="flex-1"
                />
                {applied ? (
                    <Button
                        size="small"
                        color="inherit"
                        onClick={() => {
                            setCode('');
                            onApplied(null);
                        }}
                        className="mt-1"
                    >
                        Remove
                    </Button>
                ) : (
                    <Button size="small" variant="outlined" disabled={checking || !code || items.length === 0} onClick={() => check(code)} className="mt-1">
                        Apply
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function Create({ products, customer, tables = [], defaultTableId = null, couponsEnabled = false, fulfilmentOptions, paymentMethods, defaultDeliveryFee, limits }) {
    const { currency } = useTenant();
    const dineIn = fulfilmentOptions.some((option) => option.value === 'dine_in');
    const startDineIn = dineIn && Boolean(defaultTableId);
    const [mode, setMode] = useState(startDineIn && !customer ? 'walk_in' : 'existing');
    const [picked, setPicked] = useState(customer);
    const [adding, setAdding] = useState(null);
    const [recordPayment, setRecordPayment] = useState(false);
    const [coupon, setCoupon] = useState(null);

    const form = useForm({
        customer_id: customer?.id ?? null,
        customer: { name: '', phone: '', email: '' },
        items: [],
        fulfilment: startDineIn ? 'dine_in' : 'in_store',
        dining_table_id: startDineIn ? defaultTableId : '',
        delivery_address: customer?.address ?? '',
        delivery_fee: defaultDeliveryFee ?? '0.00',
        discount: '',
        notes: '',
        completed: false,
        payment: { amount: '', method: paymentMethods[0]?.value ?? 'cash', reference: '' },
    });

    const byId = Object.fromEntries(products.map((product) => [product.id, product]));
    const lines = form.data.items.map((item) => ({ ...item, product: byId[item.product_id] })).filter((line) => line.product);
    const subtotal = lines.reduce((sum, line) => sum + cents(line.product.price) * (Number(line.quantity) || 0), 0);
    const couponDiscount = coupon ? cents(coupon.discount) : 0;
    const discount = Math.min(cents(form.data.discount) + couponDiscount, subtotal);
    const deliveryFee = form.data.fulfilment === 'delivery' ? cents(form.data.delivery_fee) : 0;
    const total = subtotal - discount + deliveryFee;
    const couponItems = form.data.items.map((item) => ({ product_id: item.product_id, quantity: Number(item.quantity) || 1 }));

    const setFulfilment = (next) => {
        form.setData('fulfilment', next);

        if (next !== 'dine_in' && mode === 'walk_in') {
            setMode('existing');
        }
    };

    const addProduct = (product) => {
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

    const setQuantity = (productId, quantity) =>
        form.setData(
            'items',
            form.data.items.map((item) => (item.product_id === productId ? { ...item, quantity } : item)),
        );

    const removeLine = (productId) => form.setData('items', form.data.items.filter((item) => item.product_id !== productId));

    const chooseCustomer = (next) => {
        setPicked(next);
        form.setData((data) => ({
            ...data,
            customer_id: next?.id ?? null,
            delivery_address: data.delivery_address || next?.address || '',
        }));
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform(({ customer: inline, customer_id, payment, dining_table_id, ...data }) => ({
            ...data,
            ...(mode === 'existing' ? { customer_id } : mode === 'new' ? { customer: inline } : {}),
            dining_table_id: data.fulfilment === 'dine_in' ? dining_table_id || null : null,
            coupon_code: coupon?.code ?? null,
            items: data.items.map((item) => ({ product_id: item.product_id, quantity: Number(item.quantity) || 0 })),
            delivery_fee: data.fulfilment === 'delivery' ? data.delivery_fee || 0 : 0,
            discount: data.discount || 0,
            delivery_address: data.fulfilment === 'delivery' ? data.delivery_address : null,
            payment: recordPayment ? { ...payment, amount: payment.amount === '' ? fromCents(total) : payment.amount } : null,
        }));
        form.post('/orders');
    };

    const itemsError = form.errors.items ?? Object.entries(form.errors).find(([key]) => key.startsWith('items.'))?.[1];

    return (
        <AppLayout title="New order">
            <Button component={Link} href="/orders" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Orders
            </Button>
            <PageHeader title="New order" description="A counter sale, or an order taken by phone or WhatsApp. Prices come from your products." />

            {products.length === 0 ? (
                <Alert severity="info">
                    Add an active product before taking orders. <Link href="/products/create" className="font-medium underline">Add one now</Link>.
                </Alert>
            ) : (
                <form onSubmit={submit} noValidate className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <Card variant="outlined">
                        <CardContent className="space-y-8">
                            <Section step={1} title="Customer">
                                <ToggleButtonGroup size="small" exclusive value={mode} onChange={(_, next) => next && setMode(next)} aria-label="Customer type">
                                    <ToggleButton value="existing">Existing customer</ToggleButton>
                                    <ToggleButton value="new">New customer</ToggleButton>
                                    {form.data.fulfilment === 'dine_in' ? <ToggleButton value="walk_in">Walk-in guest</ToggleButton> : null}
                                </ToggleButtonGroup>
                                {mode === 'walk_in' ? (
                                    <p className="text-sm text-slate-600">No customer details needed — the order is tracked by table.</p>
                                ) : mode === 'existing' ? (
                                    <CustomerPicker value={picked} autoFocus={!customer} onChange={chooseCustomer} error={form.errors.customer_id} endpoint="/orders/customers" />
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <TextField
                                            label="Name"
                                            required
                                            value={form.data.customer.name}
                                            onChange={(event) => form.setData('customer', { ...form.data.customer, name: event.target.value })}
                                            error={Boolean(form.errors['customer.name'] || form.errors.customer_id)}
                                            helperText={form.errors['customer.name'] ?? form.errors.customer_id}
                                            slotProps={{ htmlInput: { maxLength: 120 } }}
                                        />
                                        <TextField
                                            label="Phone"
                                            type="tel"
                                            value={form.data.customer.phone}
                                            onChange={(event) => form.setData('customer', { ...form.data.customer, phone: event.target.value })}
                                            error={Boolean(form.errors['customer.phone'])}
                                            helperText={form.errors['customer.phone'] ?? 'An existing customer with this number is reused.'}
                                        />
                                        <TextField
                                            label="Email"
                                            type="email"
                                            value={form.data.customer.email}
                                            onChange={(event) => form.setData('customer', { ...form.data.customer, email: event.target.value })}
                                            error={Boolean(form.errors['customer.email'])}
                                            helperText={form.errors['customer.email']}
                                        />
                                    </div>
                                )}
                            </Section>

                            <Section step={2} title="Products">
                                <Autocomplete
                                    value={adding}
                                    onChange={(_, product) => addProduct(product)}
                                    options={products}
                                    groupBy={(product) => product.category ?? 'Other'}
                                    getOptionLabel={(product) => product.name}
                                    getOptionDisabled={(product) => product.is_available === false || (product.track_stock && product.available === 0)}
                                    disabled={form.data.items.length >= limits.items}
                                    renderOption={(props, product) => {
                                        const { key, ...optionProps } = props;

                                        return (
                                            <li key={key} {...optionProps}>
                                                <div className="flex w-full items-center justify-between gap-3">
                                                    <span className="flex items-center gap-2">
                                                        <FoodTypeMark type={product.food_type} />
                                                        <span className="text-sm text-slate-900">{product.name}</span>
                                                        {product.sku ? <span className="text-xs text-slate-500">{product.sku}</span> : null}
                                                    </span>
                                                    <span className="text-right text-sm">
                                                        {formatPrice(product.price, currency)}
                                                        {product.is_available === false ? (
                                                            <span className="block text-xs text-red-600">Not available now</span>
                                                        ) : product.track_stock ? (
                                                            <span className={`block text-xs ${product.available === 0 ? 'text-red-600' : 'text-slate-500'}`}>
                                                                {product.available === 0 ? 'Out of stock' : `${product.available} in stock`}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                </div>
                                            </li>
                                        );
                                    }}
                                    renderInput={(params) => <TextField {...params} label="Add a product" placeholder="Search products" />}
                                />
                                {itemsError ? <p className="text-sm text-red-600">{itemsError}</p> : null}
                                {lines.length === 0 ? (
                                    <p className="rounded-lg border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">No products added yet.</p>
                                ) : (
                                    <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                                        {lines.map((line) => {
                                            const over = line.product.track_stock && Number(line.quantity) > line.product.available;

                                            return (
                                                <li key={line.product_id} className="flex flex-wrap items-center gap-3 p-3">
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm font-medium text-slate-900">{line.product.name}</p>
                                                        <p className={`text-xs ${over ? 'text-red-600' : 'text-slate-500'}`}>
                                                            {formatPrice(line.product.price, currency)} each
                                                            {line.product.track_stock ? ` · ${line.product.available} in stock` : ''}
                                                        </p>
                                                    </div>
                                                    <TextField
                                                        size="small"
                                                        type="number"
                                                        label="Qty"
                                                        value={line.quantity}
                                                        onChange={(event) => setQuantity(line.product_id, event.target.value)}
                                                        error={over}
                                                        slotProps={{ htmlInput: { min: 1, max: limits.quantity, step: 1 } }}
                                                        className="w-24"
                                                    />
                                                    <span className="w-24 text-right text-sm font-medium text-slate-900">
                                                        {formatPrice(fromCents(cents(line.product.price) * (Number(line.quantity) || 0)), currency)}
                                                    </span>
                                                    <IconButton size="small" aria-label={`Remove ${line.product.name}`} onClick={() => removeLine(line.product_id)}>
                                                        <CloseIcon fontSize="small" />
                                                    </IconButton>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </Section>

                            <Section step={3} title="Handover">
                                <ToggleButtonGroup
                                    size="small"
                                    exclusive
                                    value={form.data.fulfilment}
                                    onChange={(_, next) => next && setFulfilment(next)}
                                    aria-label="How the order reaches the customer"
                                >
                                    {fulfilmentOptions.map((option) => (
                                        <ToggleButton key={option.value} value={option.value}>
                                            {option.label}
                                        </ToggleButton>
                                    ))}
                                </ToggleButtonGroup>
                                {form.errors.fulfilment ? <p className="text-sm text-red-600">{form.errors.fulfilment}</p> : null}
                                {form.data.fulfilment === 'dine_in' ? (
                                    <TextField
                                        select
                                        label="Table"
                                        value={form.data.dining_table_id}
                                        onChange={(event) => form.setData('dining_table_id', event.target.value)}
                                        error={Boolean(form.errors.dining_table_id)}
                                        helperText={form.errors.dining_table_id ?? (tables.length === 0 ? 'No tables set up yet.' : 'Optional for counter orders.')}
                                        slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
                                        className="max-w-xs"
                                        fullWidth
                                    >
                                        <MenuItem value="">
                                            <em>No table</em>
                                        </MenuItem>
                                        {tables.map((table) => (
                                            <MenuItem key={table.id} value={table.id}>
                                                {table.name}
                                                <span className="ml-2 text-xs text-slate-500">
                                                    {table.seats} seats{table.area ? ` · ${table.area}` : ''}
                                                    {table.busy ? ' · has an open order' : ''}
                                                </span>
                                            </MenuItem>
                                        ))}
                                    </TextField>
                                ) : null}
                                {form.data.fulfilment === 'delivery' ? (
                                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_180px]">
                                        <TextField
                                            label="Delivery address"
                                            required
                                            multiline
                                            minRows={2}
                                            value={form.data.delivery_address}
                                            onChange={(event) => form.setData('delivery_address', event.target.value)}
                                            error={Boolean(form.errors.delivery_address)}
                                            helperText={form.errors.delivery_address}
                                            slotProps={{ htmlInput: { maxLength: 500 } }}
                                        />
                                        <TextField
                                            label="Delivery fee"
                                            type="number"
                                            value={form.data.delivery_fee}
                                            onChange={(event) => form.setData('delivery_fee', event.target.value)}
                                            error={Boolean(form.errors.delivery_fee)}
                                            helperText={form.errors.delivery_fee}
                                            slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                                        />
                                    </div>
                                ) : null}
                                <FormControlLabel
                                    control={<Checkbox checked={form.data.completed} onChange={(event) => form.setData('completed', event.target.checked)} />}
                                    label={<span className="text-sm">Handed over now — mark the order completed</span>}
                                />
                            </Section>

                            <Section step={4} title="Notes">
                                <TextField
                                    label="Notes"
                                    multiline
                                    minRows={2}
                                    fullWidth
                                    value={form.data.notes}
                                    onChange={(event) => form.setData('notes', event.target.value)}
                                    error={Boolean(form.errors.notes)}
                                    helperText={form.errors.notes ?? 'Only visible to your team.'}
                                    slotProps={{ htmlInput: { maxLength: 2000 } }}
                                />
                            </Section>
                        </CardContent>
                    </Card>

                    <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                        <Card variant="outlined">
                            <CardContent className="space-y-3">
                                <h2 className="font-semibold text-slate-900">Summary</h2>
                                <dl className="space-y-1 text-sm">
                                    <div className="flex justify-between">
                                        <dt className="text-slate-600">Subtotal</dt>
                                        <dd>{formatPrice(fromCents(subtotal), currency)}</dd>
                                    </div>
                                    <div className="flex items-center justify-between gap-3">
                                        <dt className="text-slate-600">Discount</dt>
                                        <dd>
                                            <TextField
                                                size="small"
                                                type="number"
                                                value={form.data.discount}
                                                onChange={(event) => form.setData('discount', event.target.value)}
                                                error={Boolean(form.errors.discount)}
                                                placeholder="0"
                                                slotProps={{ htmlInput: { min: 0, step: '0.01', 'aria-label': 'Discount' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                                                className="w-32"
                                            />
                                        </dd>
                                    </div>
                                    {form.errors.discount ? <p className="text-xs text-red-600">{form.errors.discount}</p> : null}
                                    {coupon ? (
                                        <div className="flex justify-between text-emerald-700">
                                            <dt>Coupon {coupon.code}</dt>
                                            <dd>−{formatPrice(coupon.discount, currency)}</dd>
                                        </div>
                                    ) : null}
                                    {form.data.fulfilment === 'delivery' ? (
                                        <div className="flex justify-between">
                                            <dt className="text-slate-600">Delivery</dt>
                                            <dd>{formatPrice(fromCents(deliveryFee), currency)}</dd>
                                        </div>
                                    ) : null}
                                    <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold">
                                        <dt>Total</dt>
                                        <dd>{formatPrice(fromCents(total), currency)}</dd>
                                    </div>
                                </dl>

                                {couponsEnabled ? <CouponField items={couponItems} applied={coupon} onApplied={setCoupon} /> : null}
                                {form.errors.coupon_code ? <p className="text-xs text-red-600">{form.errors.coupon_code}</p> : null}

                                <FormControlLabel
                                    control={<Checkbox checked={recordPayment} onChange={(event) => setRecordPayment(event.target.checked)} />}
                                    label={<span className="text-sm">Payment received now</span>}
                                />
                                {recordPayment ? (
                                    <div className="space-y-3">
                                        <TextField
                                            size="small"
                                            type="number"
                                            label="Amount"
                                            fullWidth
                                            value={form.data.payment.amount}
                                            placeholder={fromCents(total)}
                                            onChange={(event) => form.setData('payment', { ...form.data.payment, amount: event.target.value })}
                                            error={Boolean(form.errors['payment.amount'])}
                                            helperText={form.errors['payment.amount'] ?? 'Leave empty for the full total.'}
                                            slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> }, inputLabel: { shrink: true } }}
                                        />
                                        <TextField
                                            select
                                            size="small"
                                            label="Method"
                                            fullWidth
                                            value={form.data.payment.method}
                                            onChange={(event) => form.setData('payment', { ...form.data.payment, method: event.target.value })}
                                            error={Boolean(form.errors['payment.method'])}
                                            helperText={form.errors['payment.method']}
                                        >
                                            {paymentMethods.map((method) => (
                                                <MenuItem key={method.value} value={method.value}>
                                                    {method.label}
                                                </MenuItem>
                                            ))}
                                        </TextField>
                                        <TextField
                                            size="small"
                                            label="Reference"
                                            fullWidth
                                            value={form.data.payment.reference}
                                            onChange={(event) => form.setData('payment', { ...form.data.payment, reference: event.target.value })}
                                            helperText="Optional, e.g. UPI transaction ID."
                                            slotProps={{ htmlInput: { maxLength: 100 } }}
                                        />
                                    </div>
                                ) : null}

                                <Button type="submit" variant="contained" fullWidth disabled={form.processing || lines.length === 0}>
                                    {form.data.completed ? 'Complete sale' : 'Create order'}
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                </form>
            )}
        </AppLayout>
    );
}

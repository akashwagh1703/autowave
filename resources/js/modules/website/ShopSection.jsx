import { useForm } from '@inertiajs/react';
import AddIcon from '@mui/icons-material/Add';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import CloseIcon from '@mui/icons-material/Close';
import RemoveIcon from '@mui/icons-material/Remove';
import ShoppingBagIcon from '@mui/icons-material/ShoppingBagOutlined';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { postJson } from '@/utils/booking';
import { formatPrice } from '@/utils/format';
import ItemFiles from './ItemFiles';
import { ActionButton, Card, Field, Honeypot, Section, SectionHeading, inputClass, useSite } from './site';

const FULFILMENT_LABELS = { pickup: 'Pick up', delivery: 'Delivery' };

const FOOD_TYPES = {
    veg: { label: 'Vegetarian', color: '#16a34a' },
    non_veg: { label: 'Non-vegetarian', color: '#b91c1c' },
    egg: { label: 'Contains egg', color: '#ca8a04' },
};

function FoodMark({ type }) {
    const food = FOOD_TYPES[type];

    if (!food) {
        return null;
    }

    return (
        <span
            className="inline-flex h-4 w-4 shrink-0 items-center justify-center border-2"
            style={{ borderColor: food.color, borderRadius: 3 }}
            title={food.label}
            role="img"
            aria-label={food.label}
        >
            <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: food.color }} />
        </span>
    );
}

function storageKey() {
    return `aw-cart:${window.location.host}`;
}

function readCart() {
    try {
        const stored = JSON.parse(window.localStorage.getItem(storageKey()) ?? '[]');

        return Array.isArray(stored)
            ? stored.filter((item) => Number.isInteger(item?.product_id) && Number.isInteger(item?.quantity) && item.quantity > 0)
            : [];
    } catch {
        return [];
    }
}

/**
 * The visitor's cart: product ids and quantities only, kept in this browser. Prices always come
 * from the server (the quote and the order), never from here.
 */
export function useCart(shop, products) {
    const [items, setItems] = useState(() => (shop ? readCart() : []));
    const [open, setOpen] = useState(false);

    // Drop products that are no longer sold, and cap quantities at what can be ordered.
    const known = useMemo(
        () => items.filter((item) => products[item.product_id]?.in_stock).map((item) => ({ ...item, quantity: Math.min(item.quantity, products[item.product_id].max_quantity) })),
        [items, products],
    );

    useEffect(() => {
        if (shop) {
            window.localStorage.setItem(storageKey(), JSON.stringify(known));
        }
    }, [shop, known]);

    const setQuantity = useCallback(
        (productId, quantity) =>
            setItems((current) => {
                if (quantity <= 0) {
                    return current.filter((item) => item.product_id !== productId);
                }

                return current.some((item) => item.product_id === productId)
                    ? current.map((item) => (item.product_id === productId ? { ...item, quantity } : item))
                    : [...current, { product_id: productId, quantity }];
            }),
        [],
    );

    const add = useCallback(
        (productId) => {
            const product = products[productId];
            const current = known.find((item) => item.product_id === productId)?.quantity ?? 0;

            if (!product || (current === 0 && known.length >= shop.max_items)) {
                return;
            }

            setQuantity(productId, Math.min(current + 1, product.max_quantity));
        },
        [known, products, setQuantity, shop],
    );

    return {
        enabled: Boolean(shop),
        items: known,
        count: known.reduce((sum, item) => sum + item.quantity, 0),
        quantityOf: (productId) => known.find((item) => item.product_id === productId)?.quantity ?? 0,
        add,
        setQuantity,
        clear: () => setItems([]),
        open,
        setOpen,
    };
}

function Stepper({ quantity, max, onChange, label }) {
    const { theme } = useSite();

    return (
        <div className="inline-flex items-center border border-slate-200" style={{ borderRadius: theme.radius }}>
            <button type="button" onClick={() => onChange(quantity - 1)} className="px-2 py-1.5 text-slate-700 hover:text-slate-900" aria-label={`One less ${label}`}>
                <RemoveIcon sx={{ fontSize: 18 }} />
            </button>
            <span className="min-w-8 text-center text-sm font-semibold" aria-live="polite">
                {quantity}
            </span>
            <button
                type="button"
                onClick={() => onChange(quantity + 1)}
                disabled={quantity >= max}
                className="px-2 py-1.5 text-slate-700 hover:text-slate-900 disabled:opacity-40"
                aria-label={`One more ${label}`}
            >
                <AddIcon sx={{ fontSize: 18 }} />
            </button>
        </div>
    );
}

export function Products({ config, data }) {
    const { locale, theme, cart } = useSite();

    return (
        <Section id="products" tone="muted">
            <SectionHeading title={config.heading} intro={config.intro} />
            <div className="space-y-10">
                {data.map((group) => (
                    <div key={group.name ?? 'other'}>
                        {group.name && data.length > 1 ? <h3 className="mb-4 text-lg font-semibold text-slate-800">{group.name}</h3> : null}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {group.products.map((product) => {
                                const quantity = cart.quantityOf(product.id);

                                return (
                                    <Card key={product.id} className="flex flex-col overflow-hidden p-0">
                                        {product.image ? (
                                            <img src={product.image} alt={product.name} loading="lazy" className="aspect-[4/3] w-full object-cover" style={{ borderTopLeftRadius: theme.radius, borderTopRightRadius: theme.radius }} />
                                        ) : null}
                                        <div className="flex flex-1 flex-col p-5">
                                            <div className="flex items-start justify-between gap-3">
                                                <h4 className="flex items-center gap-2 font-semibold text-slate-900">
                                                    <FoodMark type={product.food_type} />
                                                    {product.name}
                                                </h4>
                                                {config.show_prices ? (
                                                    <span className="shrink-0 text-right">
                                                        <span className="block font-semibold text-slate-900">{formatPrice(product.price, locale.currency)}</span>
                                                        {product.compare_at_price ? (
                                                            <span className="block text-xs text-slate-500 line-through">{formatPrice(product.compare_at_price, locale.currency)}</span>
                                                        ) : null}
                                                    </span>
                                                ) : null}
                                            </div>
                                            {product.description ? <p className="mt-2 text-sm whitespace-pre-line text-slate-600">{product.description}</p> : null}
                                            <ItemFiles item={product} className="mt-3" />
                                            <div className="mt-auto flex items-center justify-end gap-3 pt-4">
                                                {!product.in_stock ? (
                                                    <span className="text-sm font-medium text-slate-500">Out of stock</span>
                                                ) : cart.enabled ? (
                                                    quantity > 0 ? (
                                                        <Stepper quantity={quantity} max={product.max_quantity} onChange={(next) => cart.setQuantity(product.id, next)} label={product.name} />
                                                    ) : (
                                                        <ActionButton variant="secondary" className="px-3 py-1.5" onClick={() => cart.add(product.id)}>
                                                            Add to cart
                                                        </ActionButton>
                                                    )
                                                ) : null}
                                            </div>
                                        </div>
                                    </Card>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>
        </Section>
    );
}

export function CartButton() {
    const { cart } = useSite();

    return (
        <ActionButton variant="secondary" onClick={() => cart.setOpen(true)} aria-label={`Cart, ${cart.count} ${cart.count === 1 ? 'item' : 'items'}`}>
            <ShoppingBagIcon fontSize="small" />
            <span>{cart.count}</span>
        </ActionButton>
    );
}

function Confirmation({ confirmation, onClose }) {
    const { locale } = useSite();

    return (
        <div className="flex flex-1 flex-col items-center justify-center p-6 text-center">
            <CheckCircleIcon className="text-emerald-600" sx={{ fontSize: 48 }} />
            <p className="mt-3 text-lg font-semibold text-slate-900" role="status">
                Thank you! Order {confirmation.number} received.
            </p>
            <p className="mt-2 text-slate-700">{confirmation.items}</p>
            {Number(confirmation.discount) > 0 ? <p className="mt-1 text-sm text-emerald-700">You saved {formatPrice(confirmation.discount, locale.currency)}</p> : null}
            <p className="mt-1 font-semibold text-slate-900">{formatPrice(confirmation.total, locale.currency)}</p>
            <p className="mt-3 text-sm text-slate-500">
                {confirmation.status === 'pending' ? 'We will confirm your order shortly.' : 'Your order is confirmed.'}{' '}
                {confirmation.fulfilment === 'delivery' ? 'Pay when it is delivered.' : 'Pay when you pick it up.'}
            </p>
            <ActionButton variant="secondary" className="mt-6" onClick={onClose}>
                Continue shopping
            </ActionButton>
        </div>
    );
}

export function CartDrawer({ products }) {
    const { cart, shop, locale, orderConfirmation } = useSite();
    const [view, setView] = useState('cart');
    const [quote, setQuote] = useState({ loading: false, data: null, error: null });
    const [couponInput, setCouponInput] = useState('');
    const [couponCode, setCouponCode] = useState('');
    const form = useForm({ name: '', phone: '', email: '', fulfilment: shop.fulfilment.length === 1 ? shop.fulfilment[0] : '', delivery_address: '', notes: '', company_website: '' });
    const itemsKey = JSON.stringify(cart.items);

    useEffect(() => {
        if (!cart.open || cart.items.length === 0 || view === 'done') {
            return undefined;
        }

        let cancelled = false;
        setQuote((current) => ({ ...current, loading: true, error: null }));
        const timer = setTimeout(() => {
            postJson('/cart/quote', { items: cart.items, fulfilment: form.data.fulfilment || null, coupon_code: couponCode || null })
                .then((data) => !cancelled && setQuote({ loading: false, data, error: null }))
                .catch((failure) =>
                    !cancelled &&
                    setQuote({
                        loading: false,
                        data: null,
                        error: failure?.status === 404 ? 'Online ordering is not available right now.' : Object.values(failure?.errors ?? {})[0]?.[0] ?? 'Could not load your cart. Please try again.',
                    }),
                );
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cart.open, itemsKey, form.data.fulfilment, couponCode, view]);

    useEffect(() => {
        if (!cart.open) {
            return undefined;
        }

        const onKey = (event) => event.key === 'Escape' && cart.setOpen(false);
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [cart]);

    const close = () => {
        cart.setOpen(false);

        if (view === 'done') {
            setView('cart');
        }
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            items: cart.items,
            delivery_address: data.fulfilment === 'delivery' ? data.delivery_address : null,
            coupon_code: quote.data?.coupon ? couponCode : null,
        }));
        form.post('/orders', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (page.props.orderConfirmation) {
                    cart.clear();
                    form.reset();
                    setCouponInput('');
                    setCouponCode('');
                    setView('done');
                }
            },
        });
    };

    if (!cart.open) {
        return null;
    }

    const data = quote.data;
    const lineFor = (productId) => data?.lines.find((line) => line.product_id === productId);
    const freeDeliveryGap =
        data && form.data.fulfilment === 'delivery' && shop.free_delivery_over && Number(data.subtotal) < Number(shop.free_delivery_over)
            ? (Number(shop.free_delivery_over) - Number(data.subtotal)).toFixed(2)
            : null;
    const orderErrors = [form.errors.items, form.errors.throttle, form.errors.coupon_code, ...Object.entries(form.errors).filter(([key]) => key.startsWith('items.')).map(([, message]) => message)].filter(Boolean);

    return (
        <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Your cart">
            <div className="absolute inset-0 bg-slate-900/50" onClick={close} aria-hidden="true" />
            <div className="relative flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 className="text-lg font-semibold text-slate-900">{view === 'checkout' ? 'Checkout' : 'Your cart'}</h2>
                    <button type="button" onClick={close} className="rounded-full p-1 text-slate-500 hover:text-slate-900" aria-label="Close cart">
                        <CloseIcon />
                    </button>
                </div>

                {view === 'done' && orderConfirmation ? (
                    <Confirmation confirmation={orderConfirmation} onClose={close} />
                ) : cart.items.length === 0 ? (
                    <div className="flex flex-1 flex-col items-center justify-center p-6 text-center">
                        <ShoppingBagIcon className="text-slate-300" sx={{ fontSize: 48 }} />
                        <p className="mt-3 text-slate-600">Your cart is empty.</p>
                        <ActionButton variant="secondary" className="mt-4" onClick={close}>
                            Browse products
                        </ActionButton>
                    </div>
                ) : (
                    <>
                        <div className="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                            {view === 'cart' ? (
                                <ul className="divide-y divide-slate-100">
                                    {cart.items.map((item) => {
                                        const product = products[item.product_id];
                                        const line = lineFor(item.product_id);

                                        return (
                                            <li key={item.product_id} className="flex items-center gap-3 py-3">
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-medium text-slate-900">{product.name}</p>
                                                    <p className="text-xs text-slate-500">{formatPrice(line?.unit_price ?? product.price, locale.currency)} each</p>
                                                    {line?.issue ? (
                                                        <p className="text-xs text-red-600" role="alert">
                                                            {line.issue}
                                                        </p>
                                                    ) : null}
                                                </div>
                                                <Stepper quantity={item.quantity} max={product.max_quantity} onChange={(next) => cart.setQuantity(item.product_id, next)} label={product.name} />
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : (
                                <form id="checkout-form" onSubmit={submit} className="relative space-y-4" noValidate>
                                    <Honeypot value={form.data.company_website} onChange={(value) => form.setData('company_website', value)} />
                                    <Field label="Name" required error={form.errors.name}>
                                        <input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoComplete="name" maxLength={120} />
                                    </Field>
                                    <Field label="Phone" required error={form.errors.phone}>
                                        <input className={inputClass} type="tel" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} autoComplete="tel" maxLength={20} />
                                    </Field>
                                    <Field label="Email" error={form.errors.email}>
                                        <input className={inputClass} type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} autoComplete="email" maxLength={255} />
                                    </Field>
                                    {form.data.fulfilment === 'delivery' ? (
                                        <Field label="Delivery address" required error={form.errors.delivery_address}>
                                            <textarea
                                                className={inputClass}
                                                rows={3}
                                                value={form.data.delivery_address}
                                                onChange={(event) => form.setData('delivery_address', event.target.value)}
                                                autoComplete="street-address"
                                                maxLength={500}
                                            />
                                        </Field>
                                    ) : null}
                                    <Field label="Notes" error={form.errors.notes}>
                                        <textarea className={inputClass} rows={2} value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} maxLength={500} />
                                    </Field>
                                </form>
                            )}

                            <fieldset>
                                <legend className="text-sm font-medium text-slate-700">How would you like to get it?</legend>
                                <div className="mt-2 flex gap-2">
                                    {shop.fulfilment.map((option) => (
                                        <FulfilmentChoice key={option} selected={form.data.fulfilment === option} onClick={() => form.setData('fulfilment', option)}>
                                            {FULFILMENT_LABELS[option] ?? option}
                                        </FulfilmentChoice>
                                    ))}
                                </div>
                                {form.errors.fulfilment ? (
                                    <p className="mt-1 text-sm text-red-600" role="alert">
                                        {form.errors.fulfilment}
                                    </p>
                                ) : null}
                                {form.data.fulfilment === 'delivery' && shop.delivery_note ? <p className="mt-2 text-xs text-slate-500">{shop.delivery_note}</p> : null}
                            </fieldset>

                            {shop.coupons ? (
                                <CouponField
                                    input={couponInput}
                                    onInput={setCouponInput}
                                    applied={couponCode}
                                    coupon={data?.coupon ?? null}
                                    error={couponCode ? data?.coupon_error : null}
                                    onApply={() => setCouponCode(couponInput.trim().toUpperCase())}
                                    onRemove={() => {
                                        setCouponCode('');
                                        setCouponInput('');
                                    }}
                                />
                            ) : null}
                        </div>

                        <div className="space-y-3 border-t border-slate-100 px-5 py-4">
                            {quote.error ? (
                                <p className="text-sm text-red-600" role="alert">
                                    {quote.error}
                                </p>
                            ) : null}
                            {data?.issues.filter((issue) => !data.lines.some((line) => line.issue === issue)).map((issue) => (
                                <p key={issue} className="text-sm text-red-600" role="alert">
                                    {issue}
                                </p>
                            ))}
                            {orderErrors.map((message) => (
                                <p key={message} className="text-sm text-red-600" role="alert">
                                    {message}
                                </p>
                            ))}
                            <dl className={`space-y-1 text-sm ${quote.loading ? 'opacity-60' : ''}`}>
                                <div className="flex justify-between">
                                    <dt className="text-slate-600">Subtotal</dt>
                                    <dd>{data ? formatPrice(data.subtotal, locale.currency) : '…'}</dd>
                                </div>
                                {data?.coupon && Number(data.discount) > 0 ? (
                                    <div className="flex justify-between text-emerald-700">
                                        <dt>Discount ({data.coupon.code})</dt>
                                        <dd>−{formatPrice(data.discount, locale.currency)}</dd>
                                    </div>
                                ) : null}
                                {form.data.fulfilment === 'delivery' ? (
                                    <div className="flex justify-between">
                                        <dt className="text-slate-600">Delivery</dt>
                                        <dd>{data ? (Number(data.delivery_fee) > 0 ? formatPrice(data.delivery_fee, locale.currency) : 'Free') : '…'}</dd>
                                    </div>
                                ) : null}
                                <div className="flex justify-between text-base font-semibold text-slate-900">
                                    <dt>Total</dt>
                                    <dd>{data ? formatPrice(data.total, locale.currency) : '…'}</dd>
                                </div>
                            </dl>
                            {freeDeliveryGap ? <p className="text-xs text-slate-500">Add {formatPrice(freeDeliveryGap, locale.currency)} more for free delivery.</p> : null}
                            <p className="text-xs text-slate-500">Pay when you {form.data.fulfilment === 'delivery' ? 'receive your order' : 'pick up your order'}.</p>
                            {view === 'cart' ? (
                                <ActionButton className="w-full" disabled={!data?.can_checkout || quote.loading || !form.data.fulfilment} onClick={() => setView('checkout')}>
                                    {form.data.fulfilment ? 'Checkout' : 'Choose pickup or delivery'}
                                </ActionButton>
                            ) : (
                                <div className="flex gap-2">
                                    <ActionButton variant="secondary" onClick={() => setView('cart')}>
                                        Back
                                    </ActionButton>
                                    <ActionButton type="submit" form="checkout-form" className="flex-1" disabled={form.processing || !data?.can_checkout}>
                                        {form.processing ? 'Placing order…' : 'Place order'}
                                    </ActionButton>
                                </div>
                            )}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}

function CouponField({ input, onInput, applied, coupon, error, onApply, onRemove }) {
    if (coupon) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-md bg-emerald-50 px-3 py-2 text-sm">
                <span className="text-emerald-800">
                    <span className="font-semibold">{coupon.code}</span> applied · {coupon.summary}
                </span>
                <button type="button" onClick={onRemove} className="text-emerald-800 underline">
                    Remove
                </button>
            </div>
        );
    }

    return (
        <div>
            <label htmlFor="coupon-code" className="text-sm font-medium text-slate-700">
                Coupon code
            </label>
            <div className="mt-1 flex gap-2">
                <input
                    id="coupon-code"
                    className={inputClass}
                    value={input}
                    onChange={(event) => onInput(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            onApply();
                        }
                    }}
                    maxLength={30}
                    autoComplete="off"
                />
                <ActionButton variant="secondary" onClick={onApply} disabled={!input.trim() || input.trim().toUpperCase() === applied}>
                    Apply
                </ActionButton>
            </div>
            {error ? (
                <p className="mt-1 text-sm text-red-600" role="alert">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

function FulfilmentChoice({ selected, onClick, children }) {
    const { theme } = useSite();

    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={`flex-1 border px-3 py-2 text-sm font-medium transition ${selected ? 'text-white' : 'border-slate-200 bg-white text-slate-800 hover:border-slate-400'}`}
            style={{ borderRadius: theme.radius, ...(selected ? { backgroundColor: theme.color, borderColor: theme.color } : {}) }}
        >
            {children}
        </button>
    );
}

import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import useTenant from '@/hooks/useTenant';

/**
 * Product details, price and stock settings. On create, `form.data.opening_stock` and
 * `form.data.image` are also sent; on edit stock is changed with the stock card instead.
 */
export default function ProductForm({ form, onSubmit, submitLabel, cancelHref, categories, creating = false, defaultLowStock, children }) {
    const { currency } = useTenant();
    const money = { htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } };

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-6">
            <section className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Name"
                    required
                    autoFocus
                    fullWidth
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    error={Boolean(form.errors.name)}
                    helperText={form.errors.name}
                    slotProps={{ htmlInput: { maxLength: 120 } }}
                    className="sm:col-span-2"
                />
                <TextField
                    select
                    label="Category"
                    value={form.data.product_category_id ?? ''}
                    onChange={(event) => form.setData('product_category_id', event.target.value || null)}
                    error={Boolean(form.errors.product_category_id)}
                    helperText={form.errors.product_category_id}
                    slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
                >
                    <MenuItem value="">
                        <em>No category</em>
                    </MenuItem>
                    {categories.map((category) => (
                        <MenuItem key={category.id} value={category.id}>
                            {category.name}
                        </MenuItem>
                    ))}
                </TextField>
                <TextField
                    label="SKU / code"
                    value={form.data.sku ?? ''}
                    onChange={(event) => form.setData('sku', event.target.value)}
                    error={Boolean(form.errors.sku)}
                    helperText={form.errors.sku ?? 'Optional. Your own product code.'}
                    slotProps={{ htmlInput: { maxLength: 60 } }}
                />
                <TextField
                    label="Price"
                    type="number"
                    required
                    value={form.data.price}
                    onChange={(event) => form.setData('price', event.target.value)}
                    error={Boolean(form.errors.price)}
                    helperText={form.errors.price ?? 'What customers pay.'}
                    slotProps={money}
                />
                <TextField
                    label="Original price"
                    type="number"
                    value={form.data.compare_at_price ?? ''}
                    onChange={(event) => form.setData('compare_at_price', event.target.value)}
                    error={Boolean(form.errors.compare_at_price)}
                    helperText={form.errors.compare_at_price ?? 'Optional. Shown struck through on your website, e.g. for a sale.'}
                    slotProps={money}
                />
                <TextField
                    label="Description"
                    multiline
                    minRows={3}
                    fullWidth
                    value={form.data.description ?? ''}
                    onChange={(event) => form.setData('description', event.target.value)}
                    error={Boolean(form.errors.description)}
                    helperText={form.errors.description ?? 'Shown on your website.'}
                    slotProps={{ htmlInput: { maxLength: 2000 } }}
                    className="sm:col-span-2"
                />
                {creating ? (
                    <div className="sm:col-span-2">
                        <p className="text-sm font-medium text-slate-700">Image</p>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(event) => form.setData('image', event.target.files?.[0] ?? null)}
                            className="mt-1 block text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700"
                            aria-label="Product image"
                        />
                        <p className={`mt-1 text-xs ${form.errors.image ? 'text-red-600' : 'text-slate-500'}`}>
                            {form.errors.image ?? 'Optional. JPG, PNG or WebP. Shown on your website.'}
                        </p>
                    </div>
                ) : null}
                <FormControlLabel
                    control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />}
                    label="Active — can be ordered and shown on your website"
                    className="sm:col-span-2"
                />
            </section>

            <section className="rounded-lg border border-slate-200 p-4">
                <FormControlLabel
                    control={<Switch checked={form.data.track_stock} onChange={(event) => form.setData('track_stock', event.target.checked)} />}
                    label="Track stock"
                />
                <p className="text-sm text-slate-600">
                    Orders take stock automatically and a product cannot be sold when none is left. Leave off for items you always have.
                </p>
                {form.data.track_stock ? (
                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                        {creating ? (
                            <TextField
                                label="Opening stock"
                                type="number"
                                value={form.data.opening_stock ?? ''}
                                onChange={(event) => form.setData('opening_stock', event.target.value)}
                                error={Boolean(form.errors.opening_stock)}
                                helperText={form.errors.opening_stock ?? 'How many you have now.'}
                                slotProps={{ htmlInput: { min: 0, step: 1 } }}
                            />
                        ) : null}
                        <TextField
                            label="Low-stock alert at"
                            type="number"
                            value={form.data.low_stock_threshold ?? ''}
                            onChange={(event) => form.setData('low_stock_threshold', event.target.value)}
                            error={Boolean(form.errors.low_stock_threshold)}
                            helperText={form.errors.low_stock_threshold ?? `Flag the product when stock falls to this level (default ${defaultLowStock}).`}
                            placeholder={String(defaultLowStock)}
                            slotProps={{ htmlInput: { min: 0, step: 1 }, inputLabel: { shrink: true } }}
                        />
                    </div>
                ) : null}
            </section>

            {children}

            <div className="flex justify-end gap-2">
                <Button component={Link} href={cancelHref} color="inherit">
                    Cancel
                </Button>
                <Button type="submit" variant="contained" disabled={form.processing}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}

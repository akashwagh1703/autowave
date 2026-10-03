import { Link, router, useForm, usePage } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ImageIcon from '@mui/icons-material/ImageOutlined';
import { useRef, useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ConfirmDialog from '@/components/ConfirmDialog';
import ProductForm from '@/modules/products/ProductForm';
import StockCard from '@/modules/products/StockCard';
import AttachmentsCard from '@/modules/files/AttachmentsCard';
import useTenant from '@/hooks/useTenant';

function ImageCard({ product }) {
    const { errors } = usePage().props;
    const input = useRef(null);
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    const upload = (file) => {
        if (file) {
            router.post(`/products/${product.id}/image`, { image: file }, { ...options, forceFormData: true });
        }
    };

    return (
        <Card variant="outlined">
            <CardContent>
                <h2 className="font-semibold text-slate-900">Image</h2>
                <div className="mt-3 flex aspect-square w-full items-center justify-center overflow-hidden rounded-lg bg-slate-100">
                    {product.image ? (
                        <img src={product.image.url} alt={product.name} className="h-full w-full object-cover" />
                    ) : (
                        <ImageIcon className="text-slate-400" sx={{ fontSize: 48 }} />
                    )}
                </div>
                {errors.image ? <p className="mt-2 text-sm text-red-600">{errors.image}</p> : null}
                <input
                    ref={input}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    onChange={(event) => {
                        upload(event.target.files?.[0]);
                        event.target.value = '';
                    }}
                    aria-label="Upload product image"
                />
                <div className="mt-3 flex gap-2">
                    <Button variant="outlined" size="small" disabled={busy} onClick={() => input.current?.click()}>
                        {product.image ? 'Replace' : 'Upload'}
                    </Button>
                    {product.image ? (
                        <Button size="small" color="error" disabled={busy} onClick={() => router.delete(`/products/${product.id}/image`, options)}>
                            Remove
                        </Button>
                    ) : null}
                </div>
                <p className="mt-2 text-xs text-slate-500">JPG, PNG or WebP. Square images look best.</p>
            </CardContent>
        </Card>
    );
}

export default function Edit({ product, categories, defaultLowStock, foodTypes, movements, stockReasons, openOrdersCount, files }) {
    const { can, timezone } = useTenant();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const form = useForm({
        name: product.name,
        product_category_id: product.product_category_id,
        sku: product.sku ?? '',
        price: product.price,
        compare_at_price: product.compare_at_price ?? '',
        description: product.description ?? '',
        is_active: product.is_active,
        is_available: product.is_available ?? true,
        food_type: product.food_type ?? null,
        track_stock: product.track_stock,
        low_stock_threshold: product.low_stock_threshold ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(`/products/${product.id}`);
    };

    const destroy = () =>
        router.delete(`/products/${product.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });

    return (
        <AppLayout title={`Edit ${product.name}`}>
            <Button component={Link} href="/products" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Products
            </Button>
            <PageHeader
                title={`Edit ${product.name}`}
                description="Price changes apply to new orders. Existing orders keep the price they were placed at."
                actions={
                    can('products.delete') ? (
                        <Button color="error" onClick={() => setConfirmDelete(true)}>
                            Delete
                        </Button>
                    ) : null
                }
            />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div className="space-y-6">
                    <Card variant="outlined">
                        <CardContent>
                            <ProductForm
                                form={form}
                                onSubmit={submit}
                                submitLabel="Save changes"
                                cancelHref="/products"
                                categories={categories}
                                defaultLowStock={defaultLowStock}
                                foodTypes={foodTypes}
                            />
                        </CardContent>
                    </Card>
                    {product.track_stock ? <StockCard product={product} movements={movements} reasons={stockReasons} /> : null}
                </div>
                <div className="space-y-6">
                    <ImageCard product={product} />
                    {files ? <AttachmentsCard documents={files} timezone={timezone} title="Video and brochures" namePlaceholder="e.g. Spec sheet, How to use" /> : null}
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${product.name}?`}
                description={
                    openOrdersCount > 0
                        ? `${openOrdersCount} open ${openOrdersCount === 1 ? 'order includes' : 'orders include'} this product and keep it. It can no longer be ordered.`
                        : 'It can no longer be ordered and disappears from your website. Past orders keep showing it.'
                }
                confirmLabel="Delete"
                destructive
                processing={deleting}
                onConfirm={destroy}
                onClose={() => setConfirmDelete(false)}
            />
        </AppLayout>
    );
}

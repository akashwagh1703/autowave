import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import CategoryIcon from '@mui/icons-material/CategoryOutlined';
import ImageIcon from '@mui/icons-material/ImageOutlined';
import InventoryIcon from '@mui/icons-material/Inventory2Outlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import ConfirmDialog from '@/components/ConfirmDialog';
import CategoryManager from '@/modules/services/CategoryManager';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatPrice } from '@/utils/format';

const sorts = [
    { value: 'category', label: 'By category' },
    { value: 'name', label: 'Name A–Z' },
    { value: 'price', label: 'Highest price' },
    { value: 'stock', label: 'Lowest stock' },
    { value: 'newest', label: 'Newest first' },
];

const statuses = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const stockFilters = [
    { value: 'all', label: 'Any stock' },
    { value: 'low', label: 'Low or out of stock' },
    { value: 'out', label: 'Out of stock' },
];

function StockCell({ product }) {
    if (!product.track_stock) {
        return <span className="text-slate-400">Not tracked</span>;
    }

    if (product.stock_quantity === 0) {
        return <Chip size="small" color="error" variant="outlined" label="Out of stock" />;
    }

    return <span className={product.is_low_stock ? 'font-semibold text-amber-700' : 'text-slate-900'}>{product.stock_quantity}</span>;
}

function BulkBar({ selected, onDone, canUpdate, canDelete }) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [processing, setProcessing] = useState(false);

    const run = (action) =>
        router.post('/products/bulk', { ids: selected, action }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmDelete(false);
            },
            onSuccess: onDone,
        });

    return (
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-brand-50 px-4 py-2">
            <span className="text-sm font-medium text-brand-700">{selected.length} selected</span>
            {canUpdate ? (
                <>
                    <Button size="small" disabled={processing} onClick={() => run('activate')}>
                        Activate
                    </Button>
                    <Button size="small" disabled={processing} onClick={() => run('deactivate')}>
                        Deactivate
                    </Button>
                </>
            ) : null}
            {canDelete ? (
                <Button size="small" color="error" disabled={processing} onClick={() => setConfirmDelete(true)}>
                    Delete
                </Button>
            ) : null}
            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${selected.length} product${selected.length === 1 ? '' : 's'}?`}
                description="They can no longer be ordered and disappear from your website. Past orders keep showing them."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() => run('delete')}
                onClose={() => setConfirmDelete(false)}
            />
        </div>
    );
}

export default function Index({ products, filters: initialFilters, categories, counts }) {
    const { currency, can } = useTenant();
    const { errors } = usePage().props;
    const { filters, apply, applyDebounced, loading } = useFilters('/products', initialFilters);
    const [selected, setSelected] = useState([]);
    const [managing, setManaging] = useState(false);
    const canUpdate = can('products.update');
    const canDelete = can('products.delete');
    const canSelect = canUpdate || canDelete;

    const pageIds = products.data.map((product) => product.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const toggle = (id) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    const filtering = Boolean(filters.search || filters.category || filters.status !== 'all' || filters.stock !== 'all');
    const change = (changes) => {
        setSelected([]);
        apply(changes);
    };

    const categoryButton = (value, label, count) => {
        const active = (filters.category ?? null) === value;

        return (
            <button
                key={value ?? 'all'}
                type="button"
                onClick={() => change({ category: value })}
                className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition ${active ? 'border-brand-500 bg-brand-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}
            >
                <span className="text-slate-700">{label}</span>
                <span className="font-semibold text-slate-900">{count}</span>
            </button>
        );
    };

    return (
        <AppLayout title="Products">
            <PageHeader
                title="Products"
                description="What you sell: prices, stock and what shows on your website."
                actions={
                    <>
                        {canUpdate ? (
                            <Button startIcon={<CategoryIcon />} color="inherit" onClick={() => setManaging(true)}>
                                Categories
                            </Button>
                        ) : null}
                        {can('products.create') ? (
                            <Button
                                component={Link}
                                href={filters.category && filters.category !== 'none' ? `/products/create?category=${filters.category}` : '/products/create'}
                                variant="contained"
                                startIcon={<AddIcon />}
                            >
                                Add product
                            </Button>
                        ) : null}
                    </>
                }
            />

            {counts.low > 0 && filters.stock === 'all' ? (
                <Alert
                    severity="warning"
                    className="mb-4"
                    action={
                        <Button color="inherit" size="small" onClick={() => change({ stock: 'low' })}>
                            Show
                        </Button>
                    }
                >
                    {counts.low === 1 ? '1 product is' : `${counts.low} products are`} low on stock
                    {counts.out > 0 ? ` (${counts.out} out of stock)` : ''}.
                </Alert>
            ) : null}

            <div className="mb-4 flex flex-wrap gap-2" aria-label="Categories">
                {categoryButton(null, 'All', counts.all)}
                {categories.map((category) => categoryButton(String(category.id), category.name, category.products_count))}
                {counts.uncategorised > 0 ? categoryButton('none', 'Uncategorised', counts.uncategorised) : null}
            </div>

            <Card variant="outlined">
                <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <SearchField
                        value={filters.search}
                        onChange={(search) => applyDebounced({ search })}
                        placeholder="Search name or SKU"
                        loading={loading}
                        className="min-w-64 flex-1"
                    />
                    <TextField select size="small" label="Status" value={filters.status} onChange={(event) => change({ status: event.target.value })} className="min-w-32">
                        {statuses.map((status) => (
                            <MenuItem key={status.value} value={status.value}>
                                {status.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField select size="small" label="Stock" value={filters.stock} onChange={(event) => change({ stock: event.target.value })} className="min-w-44">
                        {stockFilters.map((option) => (
                            <MenuItem key={option.value} value={option.value}>
                                {option.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField select size="small" label="Sort" value={filters.sort} onChange={(event) => change({ sort: event.target.value })} className="min-w-40">
                        {sorts.map((sort) => (
                            <MenuItem key={sort.value} value={sort.value}>
                                {sort.label}
                            </MenuItem>
                        ))}
                    </TextField>
                </div>

                {loading ? <LinearProgress /> : <div className="h-1" />}

                {errors.ids || errors.action ? (
                    <Alert severity="error" className="m-4">
                        {errors.ids ?? errors.action}
                    </Alert>
                ) : null}

                {selected.length > 0 && canSelect ? <BulkBar selected={selected} onDone={() => setSelected([])} canUpdate={canUpdate} canDelete={canDelete} /> : null}

                {products.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={InventoryIcon}
                            title={filtering ? 'No products match' : 'No products yet'}
                            description={filtering ? 'Try a different search or filter.' : 'Add what you sell, with a price and optional stock, so the team and your website can take orders.'}
                            action={
                                !filtering && can('products.create') ? (
                                    <Button component={Link} href="/products/create" variant="contained" startIcon={<AddIcon />}>
                                        Add product
                                    </Button>
                                ) : null
                            }
                        />
                    </div>
                ) : (
                    <TableContainer>
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    {canSelect ? (
                                        <TableCell padding="checkbox">
                                            <Checkbox
                                                checked={allSelected}
                                                indeterminate={!allSelected && selected.length > 0}
                                                onChange={() => setSelected(allSelected ? [] : pageIds)}
                                                slotProps={{ input: { 'aria-label': 'Select all products on this page' } }}
                                            />
                                        </TableCell>
                                    ) : null}
                                    <TableCell>Product</TableCell>
                                    <TableCell className="hidden md:table-cell">Category</TableCell>
                                    <TableCell align="right">Price</TableCell>
                                    <TableCell align="right">Stock</TableCell>
                                    <TableCell>Status</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {products.data.map((product) => (
                                    <TableRow key={product.id} hover selected={selected.includes(product.id)}>
                                        {canSelect ? (
                                            <TableCell padding="checkbox">
                                                <Checkbox
                                                    checked={selected.includes(product.id)}
                                                    onChange={() => toggle(product.id)}
                                                    slotProps={{ input: { 'aria-label': `Select ${product.name}` } }}
                                                />
                                            </TableCell>
                                        ) : null}
                                        <TableCell>
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-100">
                                                    {product.image ? (
                                                        <img src={product.image.url} alt="" className="h-full w-full object-cover" loading="lazy" />
                                                    ) : (
                                                        <ImageIcon fontSize="small" className="text-slate-400" />
                                                    )}
                                                </div>
                                                <div className="min-w-0">
                                                    {canUpdate ? (
                                                        <Link href={`/products/${product.id}/edit`} className="font-medium text-slate-900 hover:text-brand-700">
                                                            {product.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="font-medium text-slate-900">{product.name}</span>
                                                    )}
                                                    {product.sku ? <p className="text-xs text-slate-500">SKU {product.sku}</p> : null}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">{product.category?.name ?? <span className="text-slate-400">—</span>}</TableCell>
                                        <TableCell align="right">
                                            {formatPrice(product.price, currency)}
                                            {product.compare_at_price ? (
                                                <p className="text-xs text-slate-400 line-through">{formatPrice(product.compare_at_price, currency)}</p>
                                            ) : null}
                                        </TableCell>
                                        <TableCell align="right">
                                            <StockCell product={product} />
                                        </TableCell>
                                        <TableCell>
                                            <Chip size="small" variant="outlined" color={product.is_active ? 'success' : 'default'} label={product.is_active ? 'Active' : 'Inactive'} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={products.meta} noun="products" />

            {canUpdate ? (
                <CategoryManager
                    open={managing}
                    onClose={() => setManaging(false)}
                    categories={categories}
                    endpoint="/product-categories"
                    title="Product categories"
                    description="Group products in your shop and on your website. Deleting a category keeps its products, uncategorised."
                    countKey="products_count"
                    noun={['product', 'products']}
                />
            ) : null}
        </AppLayout>
    );
}

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
import ContentCutIcon from '@mui/icons-material/ContentCut';
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
import { formatMoney } from '@/utils/format';
import { formatDuration } from '@/utils/booking';

const sorts = [
    { value: 'category', label: 'By category' },
    { value: 'name', label: 'Name A–Z' },
    { value: 'price', label: 'Highest price' },
    { value: 'duration', label: 'Shortest first' },
    { value: 'newest', label: 'Newest first' },
];

const statuses = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

function BulkBar({ selected, onDone }) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [processing, setProcessing] = useState(false);

    const run = (action) =>
        router.post('/services/bulk', { ids: selected, action }, {
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
            <Button size="small" disabled={processing} onClick={() => run('activate')}>
                Activate
            </Button>
            <Button size="small" disabled={processing} onClick={() => run('deactivate')}>
                Deactivate
            </Button>
            <Button size="small" color="error" disabled={processing} onClick={() => setConfirmDelete(true)}>
                Delete
            </Button>
            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${selected.length} service${selected.length === 1 ? '' : 's'}?`}
                description="They can no longer be booked. Existing appointments keep showing them."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() => run('delete')}
                onClose={() => setConfirmDelete(false)}
            />
        </div>
    );
}

export default function Index({ services, filters: initialFilters, categories, counts }) {
    const { currency, can, hasEngine, resourceLabel } = useTenant();
    const { errors } = usePage().props;
    const { filters, apply, applyDebounced, loading } = useFilters('/services', initialFilters);
    const [selected, setSelected] = useState([]);
    const [managing, setManaging] = useState(false);
    const canManage = can('services.manage');
    const showResources = hasEngine('booking');

    const pageIds = services.data.map((service) => service.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const toggle = (id) => setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
    const filtering = Boolean(filters.search || filters.category || filters.status !== 'all');
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
        <AppLayout title="Services">
            <PageHeader
                title="Services"
                description="Your service menu: durations and prices used when booking."
                actions={
                    canManage ? (
                        <>
                            <Button startIcon={<CategoryIcon />} color="inherit" onClick={() => setManaging(true)}>
                                Categories
                            </Button>
                            <Button
                                component={Link}
                                href={filters.category && filters.category !== 'none' ? `/services/create?category=${filters.category}` : '/services/create'}
                                variant="contained"
                                startIcon={<AddIcon />}
                            >
                                Add service
                            </Button>
                        </>
                    ) : null
                }
            />

            <div className="mb-4 flex flex-wrap gap-2" aria-label="Categories">
                {categoryButton(null, 'All', counts.all)}
                {categories.map((category) => categoryButton(String(category.id), category.name, category.services_count))}
                {counts.uncategorised > 0 ? categoryButton('none', 'Uncategorised', counts.uncategorised) : null}
            </div>

            <Card variant="outlined">
                <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <SearchField
                        value={filters.search}
                        onChange={(search) => applyDebounced({ search })}
                        placeholder="Search services"
                        loading={loading}
                        className="min-w-64 flex-1"
                    />
                    <TextField select size="small" label="Status" value={filters.status} onChange={(event) => change({ status: event.target.value })} className="min-w-36">
                        {statuses.map((status) => (
                            <MenuItem key={status.value} value={status.value}>
                                {status.label}
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

                {selected.length > 0 && canManage ? <BulkBar selected={selected} onDone={() => setSelected([])} /> : null}

                {services.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={ContentCutIcon}
                            title={filtering ? 'No services match' : 'No services yet'}
                            description={filtering ? 'Try a different search or filter.' : 'Add what you offer, with a duration and price, so it can be booked.'}
                            action={
                                !filtering && canManage ? (
                                    <Button component={Link} href="/services/create" variant="contained" startIcon={<AddIcon />}>
                                        Add service
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
                                    {canManage ? (
                                        <TableCell padding="checkbox">
                                            <Checkbox
                                                checked={allSelected}
                                                indeterminate={!allSelected && selected.length > 0}
                                                onChange={() => setSelected(allSelected ? [] : pageIds)}
                                                slotProps={{ input: { 'aria-label': 'Select all services on this page' } }}
                                            />
                                        </TableCell>
                                    ) : null}
                                    <TableCell>Service</TableCell>
                                    <TableCell className="hidden md:table-cell">Category</TableCell>
                                    <TableCell>Duration</TableCell>
                                    <TableCell align="right">Price</TableCell>
                                    {showResources ? <TableCell className="hidden lg:table-cell">Offered by</TableCell> : null}
                                    <TableCell>Status</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {services.data.map((service) => (
                                    <TableRow key={service.id} hover selected={selected.includes(service.id)}>
                                        {canManage ? (
                                            <TableCell padding="checkbox">
                                                <Checkbox
                                                    checked={selected.includes(service.id)}
                                                    onChange={() => toggle(service.id)}
                                                    slotProps={{ input: { 'aria-label': `Select ${service.name}` } }}
                                                />
                                            </TableCell>
                                        ) : null}
                                        <TableCell>
                                            {canManage ? (
                                                <Link href={`/services/${service.id}/edit`} className="font-medium text-slate-900 hover:text-brand-700">
                                                    {service.name}
                                                </Link>
                                            ) : (
                                                <span className="font-medium text-slate-900">{service.name}</span>
                                            )}
                                            {service.description ? <p className="max-w-md truncate text-xs text-slate-500">{service.description}</p> : null}
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">{service.category?.name ?? <span className="text-slate-400">—</span>}</TableCell>
                                        <TableCell>{formatDuration(service.duration_minutes)}</TableCell>
                                        <TableCell align="right">{formatMoney(service.price, currency)}</TableCell>
                                        {showResources ? (
                                            <TableCell className="hidden lg:table-cell">
                                                {service.resources_count > 0 ? (
                                                    `${service.resources_count} ${service.resources_count === 1 ? resourceLabel.singular.toLowerCase() : resourceLabel.plural.toLowerCase()}`
                                                ) : (
                                                    <span className="text-amber-700">Nobody yet</span>
                                                )}
                                            </TableCell>
                                        ) : null}
                                        <TableCell>
                                            <Chip size="small" variant="outlined" color={service.is_active ? 'success' : 'default'} label={service.is_active ? 'Active' : 'Inactive'} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={services.meta} noun="services" />

            {canManage ? <CategoryManager open={managing} onClose={() => setManaging(false)} categories={categories} /> : null}
        </AppLayout>
    );
}

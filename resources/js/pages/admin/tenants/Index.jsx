import { router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import StorefrontIcon from '@mui/icons-material/Storefront';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import EmptyState from '@/components/EmptyState';
import PageHeader from '@/components/PageHeader';
import StatusChip from '@/components/StatusChip';
import { AdjustSubscriptionDialog, RecordPaymentDialog } from '@/modules/billing/admin/TenantBillingDialogs';
import { STATE_COLORS } from '@/utils/billing';
import { formatBytes, formatDate } from '@/utils/format';

function StorageDialog({ tenant, onClose }) {
    const defaultMb = tenant.storage.default_mb;
    const [value, setValue] = useState(tenant.storage.custom ? String(tenant.storage.cap_mb) : '');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState(null);

    const save = (mb) =>
        router.put(
            `/tenants/${tenant.id}/storage-limit`,
            { mb },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onClose,
                onError: (errors) => setError(errors.mb ?? 'Could not save the allowance.'),
            },
        );

    return (
        <Dialog open onClose={onClose} fullWidth maxWidth="xs">
            <DialogTitle>Storage for {tenant.name}</DialogTitle>
            <DialogContent>
                <p className="mb-2 text-sm text-slate-600">Using {formatBytes(tenant.storage.used_bytes)} for images, documents and videos.</p>
                <TextField
                    label="Allowance in MB"
                    type="number"
                    fullWidth
                    autoFocus
                    margin="dense"
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    error={Boolean(error)}
                    helperText={error ?? `Leave empty for the plan's allowance (${defaultMb} MB, 1024 MB = 1 GB). Existing files are kept if you lower it; new uploads are refused while over.`}
                    slotProps={{ htmlInput: { min: 0, step: 256 } }}
                />
            </DialogContent>
            <DialogActions>
                {tenant.storage.custom ? (
                    <Button color="inherit" disabled={processing} onClick={() => save(null)}>
                        Use default
                    </Button>
                ) : null}
                <Button onClick={onClose} color="inherit">
                    Cancel
                </Button>
                <Button variant="contained" disabled={processing} onClick={() => save(value === '' ? null : Number(value))}>
                    Save
                </Button>
            </DialogActions>
        </Dialog>
    );
}

export default function Index({ tenants, filters, plans, periods, methods }) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [editingStorage, setEditingStorage] = useState(null);
    const [recording, setRecording] = useState(null);
    const [adjusting, setAdjusting] = useState(null);

    const applyFilters = (overrides = {}) => {
        const query = { search, status, ...overrides };
        router.get('/tenants', Object.fromEntries(Object.entries(query).filter(([, value]) => value)), {
            preserveState: true,
            replace: true,
        });
    };

    const changeStatus = (tenant, action) => {
        const verb = action === 'suspend' ? 'Suspend' : 'Activate';

        if (window.confirm(`${verb} ${tenant.name}?`)) {
            router.post(`/tenants/${tenant.id}/${action}`, {}, { preserveScroll: true });
        }
    };

    return (
        <AdminLayout title="Tenants">
            <PageHeader title="Tenants" description={`${tenants.total} business workspaces`} />

            <form
                className="mb-4 flex flex-wrap gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    applyFilters({ page: undefined });
                }}
            >
                <TextField
                    size="small"
                    label="Search name or slug"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    className="w-full sm:w-72"
                />
                <TextField
                    size="small"
                    select
                    label="Status"
                    value={status}
                    onChange={(event) => {
                        setStatus(event.target.value);
                        applyFilters({ status: event.target.value });
                    }}
                    className="w-40"
                >
                    <MenuItem value="">All</MenuItem>
                    <MenuItem value="active">Active</MenuItem>
                    <MenuItem value="suspended">Suspended</MenuItem>
                </TextField>
                <Button type="submit" variant="contained">
                    Search
                </Button>
            </form>

            {tenants.data.length === 0 ? (
                <EmptyState icon={StorefrontIcon} title="No tenants found" description="Try a different search or filter." />
            ) : (
                <Card variant="outlined">
                    <div className="overflow-x-auto">
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Business</TableCell>
                                    <TableCell>Type</TableCell>
                                    <TableCell>Domain</TableCell>
                                    <TableCell align="right">Members</TableCell>
                                    <TableCell>Plan</TableCell>
                                    <TableCell>Storage</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell>Created</TableCell>
                                    <TableCell align="right">Actions</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {tenants.data.map((tenant) => (
                                    <TableRow key={tenant.id}>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-slate-900">{tenant.name}</span>
                                                {tenant.is_internal ? <Chip label="Internal" size="small" /> : null}
                                            </div>
                                            <span className="text-xs text-slate-500">{tenant.slug}</span>
                                        </TableCell>
                                        <TableCell>{tenant.business_type ?? '—'}</TableCell>
                                        <TableCell className="text-xs">{tenant.domain ?? '—'}</TableCell>
                                        <TableCell align="right">{tenant.members}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap items-center gap-1">
                                                <span className="text-sm text-slate-900">{tenant.subscription.plan?.name ?? '—'}</span>
                                                <Chip size="small" variant="outlined" color={STATE_COLORS[tenant.subscription.state] ?? 'default'} label={tenant.subscription.label} />
                                                {tenant.payment_pending ? <Chip size="small" color="warning" label="Payment to check" /> : null}
                                            </div>
                                            <span className="text-xs text-slate-500">
                                                {tenant.subscription.unlimited ? 'Never ends' : `Until ${formatDate(tenant.subscription.ends_at, 'Asia/Kolkata')}`}
                                                {tenant.subscription.next_plan ? ` · then ${tenant.subscription.next_plan.name}` : ''}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <button
                                                type="button"
                                                className="text-left text-xs text-slate-700 hover:text-brand-700"
                                                onClick={() => setEditingStorage(tenant)}
                                                aria-label={`Change storage allowance for ${tenant.name}`}
                                            >
                                                {formatBytes(tenant.storage.used_bytes)} / {formatBytes(tenant.storage.cap_mb * 1024 * 1024)}
                                                {tenant.storage.custom ? <span className="ml-1 text-slate-400">(custom)</span> : null}
                                            </button>
                                        </TableCell>
                                        <TableCell>
                                            <StatusChip status={tenant.status} />
                                        </TableCell>
                                        <TableCell>{tenant.created_at}</TableCell>
                                        <TableCell align="right" className="whitespace-nowrap">
                                            <Button size="small" disabled={tenant.is_internal} onClick={() => setRecording(tenant)}>
                                                Record payment
                                            </Button>
                                            <Button size="small" onClick={() => setAdjusting(tenant)}>
                                                Change plan
                                            </Button>
                                            {tenant.status === 'active' ? (
                                                <Button
                                                    size="small"
                                                    color="error"
                                                    disabled={tenant.is_internal}
                                                    onClick={() => changeStatus(tenant, 'suspend')}
                                                >
                                                    Suspend
                                                </Button>
                                            ) : (
                                                <Button size="small" onClick={() => changeStatus(tenant, 'activate')}>
                                                    Activate
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Card>
            )}

            {tenants.last_page > 1 ? (
                <div className="mt-4 flex justify-center">
                    <Pagination
                        count={tenants.last_page}
                        page={tenants.current_page}
                        onChange={(event, page) => applyFilters({ page })}
                        color="primary"
                    />
                </div>
            ) : null}

            {editingStorage ? <StorageDialog tenant={editingStorage} onClose={() => setEditingStorage(null)} /> : null}
            {recording ? <RecordPaymentDialog tenant={recording} plans={plans} periods={periods} methods={methods} onClose={() => setRecording(null)} /> : null}
            {adjusting ? <AdjustSubscriptionDialog tenant={adjusting} plans={plans} periods={periods} onClose={() => setAdjusting(null)} /> : null}
        </AdminLayout>
    );
}

import { router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
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

export default function Index({ tenants, filters }) {
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);

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
                                            <StatusChip status={tenant.status} />
                                        </TableCell>
                                        <TableCell>{tenant.created_at}</TableCell>
                                        <TableCell align="right">
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
        </AdminLayout>
    );
}

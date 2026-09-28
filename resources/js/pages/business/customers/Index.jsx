import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
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
import GroupsIcon from '@mui/icons-material/Groups';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatDate } from '@/utils/format';

const sorts = [
    { value: 'newest', label: 'Newest first' },
    { value: 'oldest', label: 'Oldest first' },
    { value: 'name', label: 'Name A–Z' },
];

export default function Index({ customers, filters: initialFilters, tags }) {
    const { timezone, can } = useTenant();
    const { filters, apply, applyDebounced, loading } = useFilters('/customers', initialFilters);
    const filtering = Boolean(filters.search || filters.tag);

    return (
        <AppLayout title="Customers">
            <PageHeader
                title="Customers"
                description="Everyone you serve, with their full history in one place."
                actions={
                    can('customers.create') ? (
                        <Button component={Link} href="/customers/create" variant="contained" startIcon={<AddIcon />}>
                            Add customer
                        </Button>
                    ) : null
                }
            />

            <Card variant="outlined">
                <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <SearchField
                        value={filters.search}
                        onChange={(search) => applyDebounced({ search })}
                        placeholder="Search name, phone, email, city"
                        loading={loading}
                        className="min-w-64 flex-1"
                    />
                    <TextField
                        select
                        size="small"
                        label="Tag"
                        value={filters.tag ?? ''}
                        onChange={(event) => apply({ tag: event.target.value || null })}
                        className="min-w-40"
                    >
                        <MenuItem value="">Any tag</MenuItem>
                        {tags.map((tag) => (
                            <MenuItem key={tag} value={tag}>
                                {tag}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField
                        select
                        size="small"
                        label="Sort"
                        value={filters.sort}
                        onChange={(event) => apply({ sort: event.target.value })}
                        className="min-w-40"
                    >
                        {sorts.map((sort) => (
                            <MenuItem key={sort.value} value={sort.value}>
                                {sort.label}
                            </MenuItem>
                        ))}
                    </TextField>
                </div>

                {loading ? <LinearProgress /> : <div className="h-1" />}

                {customers.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={GroupsIcon}
                            title={filtering ? 'No customers match' : 'No customers yet'}
                            description={
                                filtering
                                    ? 'Try a different search or tag.'
                                    : 'Customers are created when you convert a lead, or you can add one now.'
                            }
                            action={
                                !filtering && can('customers.create') ? (
                                    <Button component={Link} href="/customers/create" variant="contained" startIcon={<AddIcon />}>
                                        Add customer
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
                                    <TableCell>Customer</TableCell>
                                    <TableCell className="hidden md:table-cell">City</TableCell>
                                    <TableCell>Tags</TableCell>
                                    <TableCell align="right" className="hidden sm:table-cell">
                                        Leads
                                    </TableCell>
                                    <TableCell className="hidden lg:table-cell">Added</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {customers.data.map((customer) => (
                                    <TableRow key={customer.id} hover>
                                        <TableCell>
                                            <Link href={`/customers/${customer.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                                {customer.name}
                                            </Link>
                                            <p className="text-xs text-slate-500">{[customer.phone, customer.email].filter(Boolean).join(' · ') || '—'}</p>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">{customer.city ?? '—'}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {customer.tags.map((tag) => (
                                                    <Chip key={tag} label={tag} size="small" />
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell align="right" className="hidden sm:table-cell">
                                            {customer.leads_count}
                                        </TableCell>
                                        <TableCell className="hidden text-slate-600 lg:table-cell">{formatDate(customer.created_at, timezone)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={customers.meta} noun="customers" />
        </AppLayout>
    );
}

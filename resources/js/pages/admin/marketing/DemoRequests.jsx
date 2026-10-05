import { router } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import EventAvailableIcon from '@mui/icons-material/EventAvailableOutlined';
import AdminLayout from '@/layouts/AdminLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import EmptyState from '@/components/EmptyState';
import PageHeader from '@/components/PageHeader';
import Pagination from '@/components/Pagination';
import { formatDateTime } from '@/utils/format';

const STATUSES = [
    { value: 'new', label: 'New', color: 'warning' },
    { value: 'contacted', label: 'Contacted', color: 'info' },
    { value: 'converted', label: 'Became customer', color: 'success' },
    { value: 'closed', label: 'Closed', color: 'default' },
];

const STATUS = Object.fromEntries(STATUSES.map((status) => [status.value, status]));

const whatsappLink = (request) =>
    `https://wa.me/${request.phone.replace(/\D/g, '')}?text=${encodeURIComponent(`Hi ${request.name}, this is AutoWave. Thanks for asking for a demo for ${request.business_name}. When is a good time to show you around?`)}`;

export default function DemoRequests({ requests, filters, counts, appUrl }) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState(null);

    const apply = (overrides = {}) => {
        const query = { status: filters.status, search, ...overrides };
        router.get('/demo-requests', Object.fromEntries(Object.entries(query).filter(([, value]) => value)), { preserveState: true, replace: true });
    };

    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => {
            setEditing(null);
            setDeleting(null);
            setError(null);
        },
        onError: (errors) => setError(Object.values(errors)[0] ?? 'Could not save. Try again.'),
    };

    const total = Object.values(counts).reduce((sum, count) => sum + Number(count), 0);

    return (
        <AdminLayout title="Demo requests">
            <PageHeader
                title="Demo requests"
                description="People who asked for a demo on the AutoWave website. Each one is also emailed to platform admins. Call or WhatsApp them, then update the status."
            />

            <Tabs value={filters.status} onChange={(event, value) => apply({ status: value, page: undefined })} variant="scrollable" className="mb-4">
                {STATUSES.map((status) => (
                    <Tab key={status.value} value={status.value} label={counts[status.value] ? `${status.label} (${counts[status.value]})` : status.label} />
                ))}
                <Tab value="all" label={total ? `All (${total})` : 'All'} />
            </Tabs>

            <form
                className="mb-4 flex flex-wrap gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({ page: undefined });
                }}
            >
                <TextField size="small" label="Name, business, phone, email or city" value={search} onChange={(event) => setSearch(event.target.value)} className="w-full sm:w-80" />
                <Button type="submit" variant="contained">
                    Search
                </Button>
            </form>

            {requests.data.length === 0 ? (
                <EmptyState
                    icon={EventAvailableIcon}
                    title={filters.status === 'new' ? 'No new demo requests' : 'No demo requests found'}
                    description="Requests from the “Book a demo” page appear here."
                />
            ) : (
                <Card variant="outlined">
                    <div className="overflow-x-auto">
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Received</TableCell>
                                    <TableCell>Person</TableCell>
                                    <TableCell>Business</TableCell>
                                    <TableCell>Message</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell align="right">Actions</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {requests.data.map((request) => (
                                    <TableRow key={request.id} className="align-top">
                                        <TableCell className="whitespace-nowrap">{formatDateTime(request.created_at, 'Asia/Kolkata')}</TableCell>
                                        <TableCell>
                                            <span className="font-medium text-slate-900">{request.name}</span>
                                            <a href={`tel:${request.phone}`} className="block text-xs text-brand-700 hover:underline">
                                                {request.phone}
                                            </a>
                                            {request.email ? (
                                                <a href={`mailto:${request.email}`} className="block text-xs text-slate-500 hover:underline">
                                                    {request.email}
                                                </a>
                                            ) : null}
                                        </TableCell>
                                        <TableCell>
                                            <span className="font-medium text-slate-900">{request.business_name}</span>
                                            <span className="block text-xs text-slate-500">{[request.industry, request.city].filter(Boolean).join(' · ') || '—'}</span>
                                        </TableCell>
                                        <TableCell className="max-w-xs">
                                            <span className="whitespace-pre-line text-sm text-slate-700">{request.message || '—'}</span>
                                        </TableCell>
                                        <TableCell>
                                            <Chip size="small" variant="outlined" color={STATUS[request.status]?.color ?? 'default'} label={STATUS[request.status]?.label ?? request.status} />
                                            {request.handled_by ? (
                                                <span className="block text-xs text-slate-500">
                                                    by {request.handled_by}, {formatDateTime(request.handled_at, 'Asia/Kolkata', { dateStyle: 'medium' })}
                                                </span>
                                            ) : null}
                                            {request.note ? <span className="block max-w-xs whitespace-pre-line text-xs text-slate-600">{request.note}</span> : null}
                                        </TableCell>
                                        <TableCell align="right" className="whitespace-nowrap">
                                            <Button size="small" component="a" href={whatsappLink(request)} target="_blank" rel="noopener">
                                                WhatsApp
                                            </Button>
                                            <Button size="small" component="a" href={`tel:${request.phone}`}>
                                                Call
                                            </Button>
                                            <Button size="small" variant="contained" onClick={() => setEditing({ ...request, note: request.note ?? '' })}>
                                                Update
                                            </Button>
                                            <span className="mt-1 block">
                                                {request.lead_id ? (
                                                    <Button size="small" component="a" href={`${appUrl}/leads/${request.lead_id}`} target="_blank" rel="noopener">
                                                        Open lead in CRM
                                                    </Button>
                                                ) : null}
                                                <Button size="small" color="error" onClick={() => setDeleting(request)}>
                                                    Delete
                                                </Button>
                                            </span>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Card>
            )}

            <Pagination meta={requests} noun="requests" />

            <ConfirmDialog
                open={Boolean(editing)}
                title={`Update ${editing?.business_name ?? ''}`}
                confirmLabel="Save"
                processing={processing}
                onConfirm={() => router.put(`/demo-requests/${editing.id}`, { status: editing.status, note: editing.note }, options)}
                onClose={() => {
                    setEditing(null);
                    setError(null);
                }}
            >
                <TextField
                    select
                    label="Status"
                    fullWidth
                    margin="dense"
                    value={editing?.status ?? 'new'}
                    onChange={(event) => setEditing({ ...editing, status: event.target.value })}
                >
                    {STATUSES.map((status) => (
                        <MenuItem key={status.value} value={status.value}>
                            {status.label}
                        </MenuItem>
                    ))}
                </TextField>
                <TextField
                    label="Note (only admins see this)"
                    fullWidth
                    multiline
                    minRows={3}
                    margin="dense"
                    value={editing?.note ?? ''}
                    onChange={(event) => setEditing({ ...editing, note: event.target.value })}
                    placeholder="e.g. Demo booked for Friday 4 pm. Interested in the Growth plan."
                    error={Boolean(error)}
                    helperText={error}
                    slotProps={{ htmlInput: { maxLength: 1000 } }}
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(deleting)}
                title={`Delete the request from ${deleting?.business_name ?? ''}?`}
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={() => router.delete(`/demo-requests/${deleting.id}`, options)}
                onClose={() => setDeleting(null)}
            >
                <p className="text-sm text-slate-700">Use this for spam or test requests. The lead in the CRM, if any, is kept.</p>
            </ConfirmDialog>
        </AdminLayout>
    );
}

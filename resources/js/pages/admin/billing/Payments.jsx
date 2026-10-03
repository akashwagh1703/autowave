import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import PaymentsIcon from '@mui/icons-material/PaymentsOutlined';
import AdminLayout from '@/layouts/AdminLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import EmptyState from '@/components/EmptyState';
import PageHeader from '@/components/PageHeader';
import Pagination from '@/components/Pagination';
import { formatDate, formatDateTime } from '@/utils/format';
import { PAYMENT_COLORS, rupees } from '@/utils/billing';

const TABS = [
    { value: 'pending', label: 'To check' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'cancelled', label: 'Withdrawn' },
    { value: 'all', label: 'All' },
];

export default function Payments({ payments, filters, counts }) {
    const [search, setSearch] = useState(filters.search);
    const [approving, setApproving] = useState(null);
    const [rejecting, setRejecting] = useState(null);
    const [reason, setReason] = useState('');
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);

    const apply = (overrides = {}) => {
        const query = { status: filters.status, search, ...overrides };
        router.get('/billing/payments', Object.fromEntries(Object.entries(query).filter(([, value]) => value)), { preserveState: true, replace: true });
    };

    const review = (payment, action, data = {}) =>
        router.post(`/billing/payments/${payment.id}/${action}`, data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => {
                setApproving(null);
                setRejecting(null);
                setReason('');
                setError(null);
            },
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Could not save. Try again.'),
        });

    return (
        <AdminLayout title="Payments">
            <PageHeader title="Payments" description="Check each payment in your bank or UPI app before approving it. Approving extends the plan and issues the invoice." />

            <Tabs value={filters.status} onChange={(event, value) => apply({ status: value, page: undefined })} variant="scrollable" className="mb-4">
                {TABS.map((tab) => (
                    <Tab key={tab.value} value={tab.value} label={tab.value !== 'all' && counts[tab.value] ? `${tab.label} (${counts[tab.value]})` : tab.label} />
                ))}
            </Tabs>

            <form
                className="mb-4 flex flex-wrap gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({ page: undefined });
                }}
            >
                <TextField size="small" label="Business or UTR" value={search} onChange={(event) => setSearch(event.target.value)} className="w-full sm:w-72" />
                <Button type="submit" variant="contained">
                    Search
                </Button>
            </form>

            {payments.data.length === 0 ? (
                <EmptyState icon={PaymentsIcon} title={filters.status === 'pending' ? 'Nothing to check' : 'No payments found'} description="Payments owners report appear here." />
            ) : (
                <Card variant="outlined">
                    <div className="overflow-x-auto">
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Reported</TableCell>
                                    <TableCell>Business</TableCell>
                                    <TableCell>Plan</TableCell>
                                    <TableCell align="right">Amount</TableCell>
                                    <TableCell>Payment</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell align="right">Actions</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {payments.data.map((payment) => (
                                    <TableRow key={payment.id}>
                                        <TableCell className="whitespace-nowrap">{formatDateTime(payment.created_at, 'Asia/Kolkata')}</TableCell>
                                        <TableCell>
                                            <span className="font-medium text-slate-900">{payment.tenant?.name}</span>
                                            <span className="block text-xs text-slate-500">
                                                AW-{payment.tenant?.id} · {payment.submitted_by?.email}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {payment.plan} <span className="text-slate-500">({payment.period})</span>
                                            {payment.kind === 'renewal' ? <span className="block text-xs text-slate-500">from current end date</span> : null}
                                        </TableCell>
                                        <TableCell align="right" className="whitespace-nowrap">
                                            <span className="font-semibold">{rupees(payment.total)}</span>
                                            {payment.tax_amount > 0 ? <span className="block text-xs text-slate-500">incl. GST {rupees(payment.tax_amount)}</span> : null}
                                            {payment.credit > 0 ? <span className="block text-xs text-slate-500">credit {rupees(payment.credit)}</span> : null}
                                        </TableCell>
                                        <TableCell>
                                            {payment.method_label}
                                            {payment.reference ? <span className="block font-mono text-xs text-slate-700">{payment.reference}</span> : null}
                                            {payment.paid_on ? <span className="block text-xs text-slate-500">paid {formatDate(payment.paid_on, 'UTC')}</span> : null}
                                            {payment.has_proof ? (
                                                <a href={`/billing/payments/${payment.id}/proof`} target="_blank" rel="noopener" className="text-xs text-brand-700 hover:underline">
                                                    View screenshot
                                                </a>
                                            ) : null}
                                            {payment.buyer_gstin ? <span className="block text-xs text-slate-500">GSTIN {payment.buyer_gstin}</span> : null}
                                            {payment.note ? <span className="block text-xs text-slate-500">{payment.note}</span> : null}
                                        </TableCell>
                                        <TableCell>
                                            <Chip size="small" variant="outlined" color={PAYMENT_COLORS[payment.status] ?? 'default'} label={payment.status} className="capitalize" />
                                            {payment.reviewed_by ? <span className="block text-xs text-slate-500">by {payment.reviewed_by}</span> : null}
                                            {payment.rejection_reason ? <span className="block text-xs text-red-700">{payment.rejection_reason}</span> : null}
                                        </TableCell>
                                        <TableCell align="right" className="whitespace-nowrap">
                                            {payment.status === 'pending' ? (
                                                <>
                                                    <Button size="small" color="error" onClick={() => setRejecting(payment)}>
                                                        Reject
                                                    </Button>
                                                    <Button size="small" variant="contained" onClick={() => setApproving(payment)}>
                                                        Approve
                                                    </Button>
                                                </>
                                            ) : payment.invoice ? (
                                                <Button size="small" component={Link} href={`/billing/invoices/${payment.invoice.id}`}>
                                                    {payment.invoice.number}
                                                </Button>
                                            ) : null}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Card>
            )}

            <Pagination meta={payments} noun="payments" />

            <ConfirmDialog
                open={Boolean(approving)}
                title={`Approve ${approving ? rupees(approving.total) : ''} from ${approving?.tenant?.name ?? ''}?`}
                confirmLabel="Approve"
                processing={processing}
                onConfirm={() => review(approving, 'approve')}
                onClose={() => {
                    setApproving(null);
                    setError(null);
                }}
            >
                <p className="text-sm text-slate-700">
                    Only approve after you see <strong>{approving ? rupees(approving.total) : ''}</strong>
                    {approving?.reference ? (
                        <>
                            {' '}
                            with UTR <strong className="font-mono">{approving.reference}</strong>
                        </>
                    ) : null}{' '}
                    in your bank or UPI app.
                </p>
                {error ? (
                    <Alert severity="error" sx={{ mt: 2 }}>
                        {error}
                    </Alert>
                ) : null}
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(rejecting)}
                title={`Reject the payment from ${rejecting?.tenant?.name ?? ''}?`}
                confirmLabel="Reject"
                destructive
                processing={processing}
                onConfirm={() => review(rejecting, 'reject', { reason })}
                onClose={() => {
                    setRejecting(null);
                    setError(null);
                }}
            >
                <TextField
                    label="Reason (emailed to the owner)"
                    fullWidth
                    multiline
                    minRows={2}
                    margin="dense"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    placeholder="e.g. No payment with this UTR reached our account."
                    error={Boolean(error)}
                    helperText={error}
                    slotProps={{ htmlInput: { maxLength: 250 } }}
                />
            </ConfirmDialog>
        </AdminLayout>
    );
}

import { Link, router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import LanguageIcon from '@mui/icons-material/Language';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLongOutlined';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import { OrderStatusChip, PaymentChip } from '@/modules/orders/OrderChips';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatPrice } from '@/utils/format';

const ranges = [
    { value: 'all', label: 'Any time' },
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
];

export default function Index({ orders, filters: initialFilters, counts, statuses, paymentStatuses, sources }) {
    const { currency, timezone, can } = useTenant();
    const { filters, apply, applyDebounced, loading } = useFilters('/orders', initialFilters);
    const filtering = Boolean(filters.search || filters.source || filters.payment || filters.range !== 'all');

    const tabs = [
        { value: 'open', label: `Open${counts.open ? ` (${counts.open})` : ''}` },
        ...statuses.map((status) => ({
            value: status.value,
            label: status.value === 'pending' && counts.pending ? `${status.label} (${counts.pending})` : status.label,
        })),
        { value: 'all', label: 'All' },
    ];

    return (
        <AppLayout title="Orders">
            <PageHeader
                title="Orders"
                description={`${counts.today} ${counts.today === 1 ? 'order' : 'orders'} today${counts.ready ? ` · ${counts.ready} ready to hand over` : ''}.`}
                actions={
                    <>
                        {can('settings.view') ? (
                            <Button component={Link} href="/settings/commerce" variant="outlined">
                                Settings
                            </Button>
                        ) : null}
                        {can('orders.create') ? (
                            <Button component={Link} href="/orders/create" variant="contained" startIcon={<AddIcon />}>
                                New order
                            </Button>
                        ) : null}
                    </>
                }
            />

            <Card variant="outlined">
                <Tabs
                    value={filters.status}
                    onChange={(_, status) => apply({ status })}
                    variant="scrollable"
                    scrollButtons="auto"
                    className="border-b border-slate-200 px-2"
                >
                    {tabs.map((tab) => (
                        <Tab key={tab.value} value={tab.value} label={tab.label} />
                    ))}
                </Tabs>
                <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <SearchField
                        value={filters.search}
                        onChange={(search) => applyDebounced({ search })}
                        placeholder="Order number, customer or phone"
                        loading={loading}
                        className="min-w-64 flex-1"
                    />
                    <TextField select size="small" label="Payment" value={filters.payment ?? ''} onChange={(event) => apply({ payment: event.target.value || null })} className="min-w-36">
                        <MenuItem value="">Any payment</MenuItem>
                        {paymentStatuses.map((status) => (
                            <MenuItem key={status.value} value={status.value}>
                                {status.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField select size="small" label="Source" value={filters.source ?? ''} onChange={(event) => apply({ source: event.target.value || null })} className="min-w-40">
                        <MenuItem value="">Any source</MenuItem>
                        {sources.map((source) => (
                            <MenuItem key={source.value} value={source.value}>
                                {source.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField select size="small" label="Placed" value={filters.range} onChange={(event) => apply({ range: event.target.value })} className="min-w-36">
                        {ranges.map((range) => (
                            <MenuItem key={range.value} value={range.value}>
                                {range.label}
                            </MenuItem>
                        ))}
                    </TextField>
                </div>

                {loading ? <LinearProgress /> : <div className="h-1" />}

                {orders.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={ReceiptLongIcon}
                            title={filtering ? 'No orders match' : filters.status === 'open' ? 'No open orders' : 'No orders here'}
                            description={filtering ? 'Try a different search or filter.' : 'Orders from the counter, the phone and your website appear here.'}
                            action={
                                !filtering && can('orders.create') ? (
                                    <Button component={Link} href="/orders/create" variant="contained" startIcon={<AddIcon />}>
                                        New order
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
                                    <TableCell>Order</TableCell>
                                    <TableCell>Customer</TableCell>
                                    <TableCell className="hidden md:table-cell">Items</TableCell>
                                    <TableCell align="right">Total</TableCell>
                                    <TableCell>Status</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {orders.data.map((order) => (
                                    <TableRow key={order.id} hover className="cursor-pointer" onClick={() => router.visit(`/orders/${order.id}`)}>
                                        <TableCell>
                                            <Link href={`/orders/${order.id}`} className="font-medium text-slate-900 hover:text-brand-700" onClick={(event) => event.stopPropagation()}>
                                                {order.reference}
                                            </Link>
                                            <p className="flex items-center gap-1 text-xs text-slate-500">
                                                {order.source === 'website' ? <LanguageIcon sx={{ fontSize: 12 }} titleAccess="Website order" /> : null}
                                                {order.source === 'whatsapp' ? <WhatsAppIcon sx={{ fontSize: 12 }} titleAccess="WhatsApp order" /> : null}
                                                {formatDateTime(order.created_at, timezone, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            <span className="text-slate-900">{order.customer?.name ?? (order.table ? `Walk-in · ${order.table.name}` : 'Walk-in')}</span>
                                            <p className="text-xs text-slate-500">
                                                {order.customer?.phone ?? ''}
                                                {order.customer && order.table ? ` · ${order.table.name}` : ''}
                                            </p>
                                        </TableCell>
                                        <TableCell className="hidden max-w-xs md:table-cell">
                                            <p className="truncate text-sm text-slate-700">{order.item_summary}</p>
                                            <p className="text-xs text-slate-500">{order.fulfilment_label}</p>
                                        </TableCell>
                                        <TableCell align="right">
                                            <span className="font-medium text-slate-900">{formatPrice(order.total, currency)}</span>
                                            <div className="mt-0.5">
                                                <PaymentChip order={order} />
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <OrderStatusChip order={order} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={orders.meta} noun="orders" />
        </AppLayout>
    );
}

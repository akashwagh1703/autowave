import { Link } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import LinearProgress from '@mui/material/LinearProgress';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import PaymentsIcon from '@mui/icons-material/PaymentsOutlined';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatDay } from '@/utils/booking';
import { formatPrice } from '@/utils/format';

const views = [
    { value: 'overdue', label: 'Overdue', empty: 'No overdue fees. Everyone is on time.' },
    { value: 'upcoming', label: 'Due soon', empty: 'Nothing due in the coming days.' },
    { value: 'all', label: 'All unpaid', empty: 'No unpaid instalments.' },
];

export default function Index({ instalments, filters: initialFilters, summary }) {
    const { currency } = useTenant();
    const { filters, apply, applyDebounced, loading } = useFilters('/fees', initialFilters);
    const view = views.find((item) => item.value === filters.view) ?? views[0];

    return (
        <AppLayout title="Fees">
            <PageHeader title="Fees" description="Unpaid instalments of students who are still studying. Record payments on the student’s page." />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <Card variant="outlined">
                    <CardContent>
                        <p className="text-sm text-slate-500">Overdue</p>
                        <p className="text-2xl font-semibold text-red-700">{formatPrice(summary.overdue, currency)}</p>
                        <p className="text-xs text-slate-500">
                            {summary.overdue_count} instalment{summary.overdue_count === 1 ? '' : 's'}
                        </p>
                    </CardContent>
                </Card>
                <Card variant="outlined">
                    <CardContent>
                        <p className="text-sm text-slate-500">Due soon</p>
                        <p className="text-2xl font-semibold text-slate-900">{formatPrice(summary.upcoming, currency)}</p>
                    </CardContent>
                </Card>
                <Card variant="outlined">
                    <CardContent>
                        <p className="text-sm text-slate-500">Total outstanding</p>
                        <p className="text-2xl font-semibold text-slate-900">{formatPrice(summary.all, currency)}</p>
                    </CardContent>
                </Card>
            </div>

            <Card variant="outlined">
                <Tabs value={filters.view} onChange={(_, next) => apply({ view: next })} variant="scrollable" className="border-b border-slate-200 px-2">
                    {views.map((item) => (
                        <Tab key={item.value} value={item.value} label={item.label} />
                    ))}
                </Tabs>
                <div className="border-b border-slate-200 p-4">
                    <SearchField value={filters.search} onChange={(search) => applyDebounced({ search })} placeholder="Search student name or phone" loading={loading} className="w-full max-w-md" />
                </div>

                {loading ? <LinearProgress /> : <div className="h-1" />}

                {instalments.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={PaymentsIcon} title={filters.search ? 'No matching students' : 'All clear'} description={filters.search ? 'Try a different search.' : view.empty} />
                    </div>
                ) : (
                    <TableContainer>
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Student</TableCell>
                                    <TableCell className="hidden md:table-cell">Batch</TableCell>
                                    <TableCell>Due on</TableCell>
                                    <TableCell align="right">Instalment</TableCell>
                                    <TableCell align="right">Still due</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {instalments.data.map((instalment) => (
                                    <TableRow key={instalment.id} hover>
                                        <TableCell>
                                            {instalment.enrolment ? (
                                                <Link href={`/students/${instalment.enrolment.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                                    {instalment.enrolment.customer?.name ?? 'Student'}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                            <p className="text-xs text-slate-500">{instalment.enrolment?.customer?.phone ?? ''}</p>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            {[instalment.enrolment?.batch?.course, instalment.enrolment?.batch?.name].filter(Boolean).join(' · ') || '—'}
                                        </TableCell>
                                        <TableCell className={instalment.is_overdue ? 'text-red-700' : ''}>
                                            {formatDay(instalment.due_on, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}
                                        </TableCell>
                                        <TableCell align="right">
                                            #{instalment.sequence} · {formatPrice(instalment.amount, currency)}
                                        </TableCell>
                                        <TableCell align="right" className="font-medium">
                                            {formatPrice(instalment.due, currency)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={instalments.meta} noun="instalments" />
        </AppLayout>
    );
}

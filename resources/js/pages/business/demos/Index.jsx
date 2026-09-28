import { Link, router, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import EventIcon from '@mui/icons-material/EventOutlined';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import DemoActions from '@/modules/education/DemoActions';
import useTenant from '@/hooks/useTenant';
import { formatDateTime } from '@/utils/format';

const views = [
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'past', label: 'Past' },
    { value: 'all', label: 'All' },
];

const COLORS = { scheduled: 'primary', attended: 'success', no_show: 'error', cancelled: 'default' };

export default function Index({ demos, filters, counts }) {
    const { can, timezone } = useTenant();
    const { errors } = usePage().props;
    const canChange = can('students.admit');

    return (
        <AppLayout title="Demo classes">
            <PageHeader
                title="Demo classes"
                description={`${counts.today} today${counts.awaiting ? ` · ${counts.awaiting} waiting for an outcome` : ''}. Schedule a demo from an enquiry’s page.`}
                actions={
                    can('leads.view') ? (
                        <Button component={Link} href="/leads" color="inherit">
                            Enquiries
                        </Button>
                    ) : null
                }
            />

            {errors.status ? (
                <Alert severity="error" className="mb-4">
                    {errors.status}
                </Alert>
            ) : null}

            <Card variant="outlined">
                <Tabs
                    value={filters.view}
                    onChange={(_, view) => router.get('/demos', { view }, { preserveState: true, preserveScroll: true, replace: true })}
                    className="border-b border-slate-200 px-2"
                >
                    {views.map((view) => (
                        <Tab key={view.value} value={view.value} label={view.label} />
                    ))}
                </Tabs>

                {demos.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={EventIcon} title={`No ${filters.view === 'all' ? '' : `${filters.view} `}demo classes`} description="Offer a trial class to an enquiry from its page; it appears here." />
                    </div>
                ) : (
                    <TableContainer>
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>When</TableCell>
                                    <TableCell>Enquiry</TableCell>
                                    <TableCell className="hidden md:table-cell">Course</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell align="right" />
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {demos.data.map((demo) => (
                                    <TableRow key={demo.id} hover>
                                        <TableCell>{formatDateTime(demo.scheduled_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</TableCell>
                                        <TableCell>
                                            {demo.lead && !demo.lead.deleted && can('leads.view') ? (
                                                <Link href={`/leads/${demo.lead.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                                    {demo.lead.name}
                                                </Link>
                                            ) : (
                                                <span className="text-slate-900">{demo.lead?.name ?? '—'}</span>
                                            )}
                                            <p className="text-xs text-slate-500">{demo.lead?.phone ?? ''}</p>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            {[demo.course?.name, demo.batch?.name].filter(Boolean).join(' · ') || '—'}
                                            {demo.notes ? <p className="line-clamp-1 text-xs text-slate-500">{demo.notes}</p> : null}
                                        </TableCell>
                                        <TableCell>
                                            <Chip label={demo.status_label} size="small" color={COLORS[demo.status] ?? 'default'} variant="outlined" />
                                        </TableCell>
                                        <TableCell align="right">
                                            {demo.status === 'scheduled' && canChange ? <DemoActions demo={demo} /> : null}
                                            {demo.status === 'attended' && demo.lead && !demo.lead.deleted && can('students.admit') ? (
                                                <Button size="small" component={Link} href={`/students/admit?lead=${demo.lead.id}`}>
                                                    Admit
                                                </Button>
                                            ) : null}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={demos.meta} noun="demo classes" />
        </AppLayout>
    );
}

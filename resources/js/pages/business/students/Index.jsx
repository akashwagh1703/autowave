import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import Tab from '@mui/material/Tab';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import PersonAddIcon from '@mui/icons-material/PersonAddAlt';
import SchoolIcon from '@mui/icons-material/SchoolOutlined';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import EnrolmentStatusChip from '@/modules/education/EnrolmentStatusChip';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatDay } from '@/utils/booking';
import { formatPrice } from '@/utils/format';

const tabs = [
    { value: 'active', label: 'Studying' },
    { value: 'completed', label: 'Completed' },
    { value: 'dropped', label: 'Dropped' },
    { value: 'all', label: 'All' },
];

export default function Index({ students, filters: initialFilters, counts, courses, batches }) {
    const { can, currency } = useTenant();
    const { filters, apply, applyDebounced, loading } = useFilters('/students', initialFilters);
    const filtering = Boolean(filters.search || filters.course || filters.batch || filters.dues);
    const batchOptions = filters.course ? batches.filter((batch) => batch.course_id === filters.course) : batches;

    return (
        <AppLayout title="Students">
            <PageHeader
                title="Students"
                description={`${counts.active} studying${counts.with_dues ? ` · ${counts.with_dues} with fees due` : ''}`}
                actions={
                    can('students.admit') ? (
                        <Button component={Link} href="/students/admit" variant="contained" startIcon={<PersonAddIcon />}>
                            Admit student
                        </Button>
                    ) : null
                }
            />

            <Card variant="outlined">
                <Tabs value={filters.status} onChange={(_, status) => apply({ status })} variant="scrollable" className="border-b border-slate-200 px-2">
                    {tabs.map((tab) => (
                        <Tab key={tab.value} value={tab.value} label={tab.label} />
                    ))}
                </Tabs>

                <div className="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
                    <SearchField
                        value={filters.search}
                        onChange={(search) => applyDebounced({ search })}
                        placeholder="Search name, phone or email"
                        loading={loading}
                        className="min-w-64 flex-1"
                    />
                    <TextField
                        select
                        size="small"
                        label="Course"
                        value={filters.course ?? ''}
                        onChange={(event) => apply({ course: event.target.value || null, batch: null })}
                        className="min-w-40"
                    >
                        <MenuItem value="">Any course</MenuItem>
                        {courses.map((course) => (
                            <MenuItem key={course.id} value={course.id}>
                                {course.name}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField select size="small" label="Batch" value={filters.batch ?? ''} onChange={(event) => apply({ batch: event.target.value || null })} className="min-w-48">
                        <MenuItem value="">Any batch</MenuItem>
                        {batchOptions.map((batch) => (
                            <MenuItem key={batch.id} value={batch.id}>
                                {batch.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <FormControlLabel
                        control={<Switch size="small" checked={Boolean(filters.dues)} onChange={(event) => apply({ dues: event.target.checked ? 1 : null })} />}
                        label={<span className="text-sm">Fees due</span>}
                    />
                </div>

                {loading ? <LinearProgress /> : <div className="h-1" />}

                {students.data.length === 0 ? (
                    <div className="p-6">
                        <EmptyState
                            icon={SchoolIcon}
                            title={filtering ? 'No students match' : 'No students here yet'}
                            description={filtering ? 'Try a different search or filter.' : 'Admit a student from an enquiry or directly to a batch.'}
                            action={
                                !filtering && can('students.admit') ? (
                                    <Button component={Link} href="/students/admit" variant="contained" startIcon={<PersonAddIcon />}>
                                        Admit student
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
                                    <TableCell>Student</TableCell>
                                    <TableCell>Batch</TableCell>
                                    <TableCell className="hidden md:table-cell">Admitted</TableCell>
                                    <TableCell align="right">Balance</TableCell>
                                    <TableCell className="hidden lg:table-cell">Next due</TableCell>
                                    <TableCell>Status</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {students.data.map((student) => (
                                    <TableRow key={student.id} hover>
                                        <TableCell>
                                            <Link href={`/students/${student.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                                                {student.customer?.name ?? 'Deleted student'}
                                            </Link>
                                            <p className="text-xs text-slate-500">{student.customer?.phone ?? ''}</p>
                                        </TableCell>
                                        <TableCell>{student.batch?.label ?? '—'}</TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            {student.enrolled_on ? formatDay(student.enrolled_on, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'}
                                        </TableCell>
                                        <TableCell align="right">
                                            {Number(student.balance) > 0 ? formatPrice(student.balance, currency) : <span className="text-emerald-700">Paid</span>}
                                        </TableCell>
                                        <TableCell className="hidden lg:table-cell">
                                            {student.next_due ? (
                                                <span className={student.next_due.is_overdue ? 'text-red-700' : 'text-slate-700'}>
                                                    {formatPrice(student.next_due.due, currency)} · {formatDay(student.next_due.due_on, { day: 'numeric', month: 'short' })}
                                                    {student.next_due.is_overdue ? <Chip label="Overdue" size="small" color="error" variant="outlined" className="ml-2" /> : null}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <EnrolmentStatusChip status={student.status} label={student.status_label} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </Card>

            <Pagination meta={students.meta} noun="students" />
        </AppLayout>
    );
}

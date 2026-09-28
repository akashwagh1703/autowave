import { Link, router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import IconButton from '@mui/material/IconButton';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import EditIcon from '@mui/icons-material/Edit';
import PersonAddIcon from '@mui/icons-material/PersonAddAlt';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ConfirmDialog from '@/components/ConfirmDialog';
import useTenant from '@/hooks/useTenant';
import { addDays, formatDay } from '@/utils/booking';
import { formatPrice } from '@/utils/format';

const SHORT = { present: 'P', absent: 'A', late: 'L', excused: 'E' };

function AttendanceCard({ batch, students, attendance, statuses, canMark }) {
    const initialMarks = Object.fromEntries(students.filter((student) => student.mark).map((student) => [student.id, student.mark]));
    const form = useForm({ date: attendance.date, topic: attendance.topic ?? '', marks: initialMarks });
    const marked = Object.keys(form.data.marks).length;

    const goTo = (date) => router.get(`/batches/${batch.id}`, date === attendance.today ? {} : { date }, { preserveScroll: true });
    const setMark = (id, status) => form.setData('marks', { ...form.data.marks, [id]: status });
    const markAll = (status) => form.setData('marks', Object.fromEntries(students.map((student) => [student.id, status])));

    const submit = (event) => {
        event.preventDefault();
        form.post(`/batches/${batch.id}/attendance`, { preserveScroll: true });
    };

    return (
        <Card variant="outlined">
            <CardContent>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="font-semibold text-slate-900">Attendance</h2>
                    <div className="flex items-center gap-1">
                        <IconButton size="small" aria-label="Previous day" onClick={() => goTo(addDays(attendance.date, -1))}>
                            <ChevronLeftIcon />
                        </IconButton>
                        <TextField
                            type="date"
                            size="small"
                            value={attendance.date}
                            onChange={(event) => event.target.value && goTo(event.target.value)}
                            slotProps={{ htmlInput: { max: attendance.today, 'aria-label': 'Class date' } }}
                        />
                        <IconButton size="small" aria-label="Next day" disabled={attendance.date >= attendance.today} onClick={() => goTo(addDays(attendance.date, 1))}>
                            <ChevronRightIcon />
                        </IconButton>
                    </div>
                </div>
                <p className="mt-1 text-sm text-slate-600">
                    {formatDay(attendance.date)}
                    {attendance.saved ? ' · saved' : ''}
                    {!attendance.meets ? ' · not a regular class day' : ''}
                </p>

                {students.length === 0 ? (
                    <p className="mt-4 text-sm text-slate-500">No active students in this batch.</p>
                ) : (
                    <form onSubmit={submit} className="mt-4 space-y-3">
                        {form.errors.marks || form.errors.date ? <Alert severity="error">{form.errors.marks ?? form.errors.date}</Alert> : null}
                        {canMark ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="small" onClick={() => markAll('present')}>
                                    Mark all present
                                </Button>
                                <Button size="small" color="inherit" onClick={() => markAll('absent')}>
                                    Mark all absent
                                </Button>
                                <span className="text-xs text-slate-500">
                                    {marked} of {students.length} marked
                                </span>
                            </div>
                        ) : null}
                        <ul className="divide-y divide-slate-100">
                            {students.map((student) => (
                                <li key={student.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <div>
                                        <Link href={`/students/${student.id}`} className="text-sm font-medium text-slate-900 hover:text-brand-700">
                                            {student.customer?.name ?? 'Student'}
                                        </Link>
                                        <p className="text-xs text-slate-500">
                                            {student.attendance_rate !== null ? `${student.attendance_rate}% attendance` : 'No attendance yet'}
                                        </p>
                                    </div>
                                    <ToggleButtonGroup
                                        exclusive
                                        size="small"
                                        value={form.data.marks[student.id] ?? null}
                                        onChange={(_, value) => value && setMark(student.id, value)}
                                        disabled={!canMark}
                                        aria-label={`Attendance for ${student.customer?.name ?? 'student'}`}
                                    >
                                        {statuses.map((status) => (
                                            <ToggleButton key={status.value} value={status.value} aria-label={status.label} title={status.label} className="w-10">
                                                {SHORT[status.value] ?? status.label.charAt(0)}
                                            </ToggleButton>
                                        ))}
                                    </ToggleButtonGroup>
                                </li>
                            ))}
                        </ul>
                        {canMark ? (
                            <div className="flex flex-wrap items-start gap-3">
                                <TextField
                                    size="small"
                                    label="Topic covered"
                                    value={form.data.topic}
                                    onChange={(event) => form.setData('topic', event.target.value)}
                                    error={Boolean(form.errors.topic)}
                                    helperText={form.errors.topic ?? 'Optional.'}
                                    slotProps={{ htmlInput: { maxLength: 200 } }}
                                    className="min-w-64 flex-1"
                                />
                                <Button type="submit" variant="contained" disabled={form.processing || marked === 0}>
                                    Save attendance
                                </Button>
                            </div>
                        ) : null}
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

export default function Show({ batch, students, attendance, sessions, attendanceStatuses }) {
    const { can, currency } = useTenant();
    const { errors } = usePage().props;
    const [deleting, setDeleting] = useState(false);
    const [processing, setProcessing] = useState(false);

    const destroy = () =>
        router.delete(`/batches/${batch.id}`, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(false);
            },
        });

    const details = [
        batch.schedule,
        batch.teacher?.name,
        batch.room,
        batch.starts_on ? `From ${formatDay(batch.starts_on, { day: 'numeric', month: 'short', year: 'numeric' })}` : null,
        batch.ends_on ? `until ${formatDay(batch.ends_on, { day: 'numeric', month: 'short', year: 'numeric' })}` : null,
    ].filter(Boolean);

    return (
        <AppLayout title={batch.label}>
            <Button component={Link} href="/courses" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Courses
            </Button>
            <PageHeader
                title={batch.label}
                description={details.join(' · ') || 'No schedule set'}
                actions={
                    <>
                        {can('courses.manage') ? (
                            <>
                                <Button color="error" onClick={() => setDeleting(true)}>
                                    Delete
                                </Button>
                                <Button component={Link} href={`/batches/${batch.id}/edit`} color="inherit" startIcon={<EditIcon />}>
                                    Edit
                                </Button>
                            </>
                        ) : null}
                        {can('students.admit') && batch.is_active ? (
                            <Button component={Link} href={`/students/admit?batch=${batch.id}`} variant="contained" startIcon={<PersonAddIcon />} disabled={batch.seats_left === 0}>
                                Admit student
                            </Button>
                        ) : null}
                    </>
                }
            />

            {errors.batch ? (
                <Alert severity="error" className="mb-4">
                    {errors.batch}
                </Alert>
            ) : null}

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <Card variant="outlined">
                    <CardContent>
                        <p className="text-sm text-slate-500">Students</p>
                        <p className="text-2xl font-semibold text-slate-900">
                            {batch.students_count ?? 0}
                            {batch.capacity ? <span className="text-base font-normal text-slate-500"> / {batch.capacity}</span> : null}
                        </p>
                        {batch.seats_left !== null ? <p className="text-xs text-slate-500">{batch.seats_left} seats left</p> : null}
                    </CardContent>
                </Card>
                <Card variant="outlined">
                    <CardContent>
                        <p className="text-sm text-slate-500">Fee</p>
                        <p className="text-2xl font-semibold text-slate-900">{formatPrice(batch.effective_fee, currency)}</p>
                        <p className="text-xs text-slate-500">{batch.fee !== null ? 'Batch fee' : 'Course fee'}</p>
                    </CardContent>
                </Card>
                <Card variant="outlined">
                    <CardContent>
                        <p className="text-sm text-slate-500">Classes recorded</p>
                        <p className="text-2xl font-semibold text-slate-900">{sessions.length}</p>
                        <p className="text-xs text-slate-500">{batch.is_active ? 'Open for admissions' : 'Closed for admissions'}</p>
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <AttendanceCard
                        key={`${attendance.date}-${attendance.saved}`}
                        batch={batch}
                        students={students}
                        attendance={attendance}
                        statuses={attendanceStatuses}
                        canMark={can('students.attendance')}
                    />
                </div>
                <div className="space-y-6">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Recent classes</h2>
                            {sessions.length === 0 ? (
                                <p className="mt-2 text-sm text-slate-500">No attendance taken yet.</p>
                            ) : (
                                <ul className="mt-2 divide-y divide-slate-100">
                                    {sessions.map((session) => (
                                        <li key={session.id} className="py-2">
                                            <button
                                                type="button"
                                                onClick={() => router.get(`/batches/${batch.id}`, { date: session.held_on }, { preserveScroll: true })}
                                                className="text-left text-sm font-medium text-slate-900 hover:text-brand-700"
                                            >
                                                {formatDay(session.held_on, { weekday: 'short', day: 'numeric', month: 'short' })}
                                            </button>
                                            <p className="text-xs text-slate-500">
                                                {session.present_count}/{session.records_count} attended
                                                {session.topic ? ` · ${session.topic}` : ''}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Fees due</h2>
                            {students.filter((student) => Number(student.balance) > 0).length === 0 ? (
                                <p className="mt-2 text-sm text-slate-500">Everyone is paid up.</p>
                            ) : (
                                <ul className="mt-2 divide-y divide-slate-100">
                                    {students
                                        .filter((student) => Number(student.balance) > 0)
                                        .map((student) => (
                                            <li key={student.id} className="flex justify-between py-2 text-sm">
                                                <Link href={`/students/${student.id}`} className="text-slate-900 hover:text-brand-700">
                                                    {student.customer?.name ?? 'Student'}
                                                </Link>
                                                <span className="text-slate-600">{formatPrice(student.balance, currency)}</span>
                                            </li>
                                        ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={deleting}
                title={`Delete ${batch.name}?`}
                description="Batches with active students can’t be deleted. Past students keep their records."
                confirmLabel="Delete batch"
                destructive
                processing={processing}
                onConfirm={destroy}
                onClose={() => setDeleting(false)}
            />
        </AppLayout>
    );
}

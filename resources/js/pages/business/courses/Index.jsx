import { Link, router, useForm, usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditIcon from '@mui/icons-material/Edit';
import SchoolIcon from '@mui/icons-material/SchoolOutlined';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import EmptyState from '@/components/EmptyState';
import ConfirmDialog from '@/components/ConfirmDialog';
import useTenant from '@/hooks/useTenant';
import { formatPrice } from '@/utils/format';

function CourseDialog({ course, open, onClose }) {
    const { currency } = useTenant();
    const form = useForm({
        name: course?.name ?? '',
        description: course?.description ?? '',
        fee: course?.fee ?? '',
        duration_label: course?.duration_label ?? '',
        is_active: course?.is_active ?? true,
    });

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (course) {
            form.put(`/courses/${course.id}`, options);
        } else {
            form.post('/courses', options);
        }
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>{course ? 'Edit course' : 'Add course'}</DialogTitle>
                <DialogContent className="space-y-4">
                    <TextField
                        label="Name"
                        required
                        fullWidth
                        autoFocus
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={Boolean(form.errors.name)}
                        helperText={form.errors.name ?? 'e.g. JEE Mains Foundation, Spoken English'}
                        slotProps={{ htmlInput: { maxLength: 120 } }}
                        className="mt-1"
                    />
                    <TextField
                        label="Description"
                        fullWidth
                        multiline
                        minRows={2}
                        value={form.data.description ?? ''}
                        onChange={(event) => form.setData('description', event.target.value)}
                        error={Boolean(form.errors.description)}
                        helperText={form.errors.description ?? 'Shown on your website.'}
                        slotProps={{ htmlInput: { maxLength: 2000 } }}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label="Course fee"
                            type="number"
                            value={form.data.fee ?? ''}
                            onChange={(event) => form.setData('fee', event.target.value)}
                            error={Boolean(form.errors.fee)}
                            helperText={form.errors.fee ?? 'Batches can override it.'}
                            slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                        />
                        <TextField
                            label="Duration"
                            value={form.data.duration_label ?? ''}
                            onChange={(event) => form.setData('duration_label', event.target.value)}
                            error={Boolean(form.errors.duration_label)}
                            helperText={form.errors.duration_label ?? 'e.g. 6 months'}
                            slotProps={{ htmlInput: { maxLength: 60 } }}
                        />
                    </div>
                    <FormControlLabel
                        control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />}
                        label="Open for admissions"
                    />
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        {course ? 'Save' : 'Add course'}
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

function BatchRow({ batch }) {
    const { currency } = useTenant();
    const full = batch.seats_left === 0;

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
            <div className="min-w-48 flex-1">
                <Link href={`/batches/${batch.id}`} className="font-medium text-slate-900 hover:text-brand-700">
                    {batch.name}
                </Link>
                {!batch.is_active ? <Chip label="Inactive" size="small" variant="outlined" className="ml-2" /> : null}
                <p className="text-xs text-slate-500">
                    {batch.schedule ?? 'No schedule set'}
                    {batch.teacher ? ` · ${batch.teacher.name}` : ''}
                    {batch.room ? ` · ${batch.room}` : ''}
                </p>
            </div>
            <span className="text-sm text-slate-600">
                {batch.students_count ?? 0}
                {batch.capacity ? ` / ${batch.capacity}` : ''} students
                {full ? <Chip label="Full" size="small" color="warning" variant="outlined" className="ml-2" /> : null}
            </span>
            <span className="w-24 text-right text-sm text-slate-900">{formatPrice(batch.effective_fee, currency)}</span>
        </li>
    );
}

export default function Index({ courses, showInactive, counts }) {
    const { can, currency } = useTenant();
    const { errors } = usePage().props;
    const canManage = can('courses.manage');
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [processing, setProcessing] = useState(false);

    const destroy = () =>
        router.delete(`/courses/${deleting.id}`, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });

    return (
        <AppLayout title="Courses">
            <PageHeader
                title="Courses"
                description={`${counts.courses} active course${counts.courses === 1 ? '' : 's'} · ${counts.batches} active batch${counts.batches === 1 ? '' : 'es'}`}
                actions={
                    canManage ? (
                        <>
                            <Button component={Link} href="/batches/create" color="inherit" startIcon={<AddIcon />}>
                                Add batch
                            </Button>
                            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing({})}>
                                Add course
                            </Button>
                        </>
                    ) : null
                }
            />

            {errors.course ? (
                <Alert severity="error" className="mb-4">
                    {errors.course}
                </Alert>
            ) : null}

            <FormControlLabel
                control={<Switch size="small" checked={showInactive} onChange={(event) => router.get('/courses', event.target.checked ? { inactive: 1 } : {}, { preserveScroll: true })} />}
                label={<span className="text-sm text-slate-600">Show inactive courses and batches</span>}
                className="mb-4"
            />

            {courses.length === 0 ? (
                <EmptyState
                    icon={SchoolIcon}
                    title="No courses yet"
                    description="Add the courses you teach, then create batches with a schedule, teacher and capacity."
                    action={
                        canManage ? (
                            <Button variant="contained" startIcon={<AddIcon />} onClick={() => setEditing({})}>
                                Add course
                            </Button>
                        ) : null
                    }
                />
            ) : (
                <div className="space-y-4">
                    {courses.map((course) => (
                        <Card key={course.id} variant="outlined">
                            <div className="flex flex-wrap items-start gap-3 border-b border-slate-200 px-4 py-3">
                                <div className="min-w-0 flex-1">
                                    <h2 className="font-semibold text-slate-900">
                                        {course.name}
                                        {!course.is_active ? <Chip label="Inactive" size="small" variant="outlined" className="ml-2" /> : null}
                                    </h2>
                                    <p className="text-sm text-slate-600">
                                        {[course.fee !== null ? formatPrice(course.fee, currency) : null, course.duration_label].filter(Boolean).join(' · ') || 'No fee set'}
                                    </p>
                                    {course.description ? <p className="mt-1 line-clamp-2 text-sm text-slate-500">{course.description}</p> : null}
                                </div>
                                {canManage ? (
                                    <div className="flex items-center gap-1">
                                        <Button size="small" component={Link} href={`/batches/create?course=${course.id}`} startIcon={<AddIcon />}>
                                            Batch
                                        </Button>
                                        <IconButton size="small" aria-label={`Edit ${course.name}`} onClick={() => setEditing(course)}>
                                            <EditIcon fontSize="small" />
                                        </IconButton>
                                        <IconButton size="small" aria-label={`Delete ${course.name}`} onClick={() => setDeleting(course)}>
                                            <DeleteOutlineIcon fontSize="small" />
                                        </IconButton>
                                    </div>
                                ) : null}
                            </div>
                            {course.batches.length === 0 ? (
                                <p className="px-4 py-3 text-sm text-slate-500">No batches yet.</p>
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {course.batches.map((batch) => (
                                        <BatchRow key={batch.id} batch={batch} />
                                    ))}
                                </ul>
                            )}
                        </Card>
                    ))}
                </div>
            )}

            {editing ? <CourseDialog key={editing.id ?? 'new'} course={editing.id ? editing : null} open onClose={() => setEditing(null)} /> : null}

            <ConfirmDialog
                open={deleting !== null}
                title={`Delete ${deleting?.name ?? 'course'}?`}
                description="Courses with students still studying can’t be deleted — mark them inactive instead. Past students keep their records."
                confirmLabel="Delete course"
                destructive
                processing={processing}
                onConfirm={destroy}
                onClose={() => setDeleting(null)}
            />
        </AppLayout>
    );
}

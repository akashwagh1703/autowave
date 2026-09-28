import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { useState } from 'react';
import DemoActions from '@/modules/education/DemoActions';
import useTenant from '@/hooks/useTenant';
import { formatDateTime } from '@/utils/format';

const COLORS = { scheduled: 'primary', attended: 'success', no_show: 'error', cancelled: 'default' };

function ScheduleDemoDialog({ lead, courses, open, onClose }) {
    const form = useForm({ scheduled_at: '', course_id: '', batch_id: '', notes: '' });
    const course = courses.find((item) => item.id === Number(form.data.course_id));

    const submit = (event) => {
        event.preventDefault();
        form.post(`/leads/${lead.id}/demos`, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>Schedule a demo class</DialogTitle>
                <DialogContent className="space-y-4">
                    <TextField
                        label="Date and time"
                        type="datetime-local"
                        required
                        fullWidth
                        value={form.data.scheduled_at}
                        onChange={(event) => form.setData('scheduled_at', event.target.value)}
                        error={Boolean(form.errors.scheduled_at)}
                        helperText={form.errors.scheduled_at}
                        slotProps={{ inputLabel: { shrink: true } }}
                        className="mt-1"
                    />
                    <TextField
                        select
                        label="Course"
                        fullWidth
                        value={form.data.course_id}
                        onChange={(event) => form.setData((data) => ({ ...data, course_id: event.target.value, batch_id: '' }))}
                        error={Boolean(form.errors.course_id)}
                        helperText={form.errors.course_id ?? 'Optional.'}
                    >
                        <MenuItem value="">
                            <em>Not decided</em>
                        </MenuItem>
                        {courses.map((item) => (
                            <MenuItem key={item.id} value={item.id}>
                                {item.name}
                            </MenuItem>
                        ))}
                    </TextField>
                    {course?.batches.length ? (
                        <TextField
                            select
                            label="Sit in with batch"
                            fullWidth
                            value={form.data.batch_id}
                            onChange={(event) => form.setData('batch_id', event.target.value)}
                            error={Boolean(form.errors.batch_id)}
                            helperText={form.errors.batch_id ?? 'Optional.'}
                        >
                            <MenuItem value="">
                                <em>Separate demo</em>
                            </MenuItem>
                            {course.batches.map((batch) => (
                                <MenuItem key={batch.id} value={batch.id}>
                                    {batch.name}
                                </MenuItem>
                            ))}
                        </TextField>
                    ) : null}
                    <TextField
                        label="Notes"
                        fullWidth
                        multiline
                        minRows={2}
                        value={form.data.notes}
                        onChange={(event) => form.setData('notes', event.target.value)}
                        error={Boolean(form.errors.notes)}
                        helperText={form.errors.notes}
                        slotProps={{ htmlInput: { maxLength: 1000 } }}
                    />
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        Schedule
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

/** Demo classes and admissions on the lead page, for coaching businesses. */
export default function LeadCoachingCard({ lead, education }) {
    const { can, timezone } = useTenant();
    const [scheduling, setScheduling] = useState(false);
    const lost = lead.stage?.outcome === 'lost';
    const canAdmit = can('students.admit') && !lost;

    return (
        <Card variant="outlined">
            <CardContent>
                <h2 className="font-semibold text-slate-900">Coaching</h2>

                {education.enrolments.length > 0 ? (
                    <ul className="mt-3 space-y-1 text-sm">
                        {education.enrolments.map((enrolment) => (
                            <li key={enrolment.id} className="flex items-center justify-between gap-2">
                                <Link href={`/students/${enrolment.id}`} className="text-brand-700 hover:underline">
                                    {enrolment.batch ?? 'Admission'}
                                </Link>
                                <span className="text-xs text-slate-500">{enrolment.status_label}</span>
                            </li>
                        ))}
                    </ul>
                ) : null}

                <h3 className="mt-4 text-sm font-medium text-slate-700">Demo classes</h3>
                {education.demos.length === 0 ? (
                    <p className="mt-1 text-sm text-slate-500">None yet.</p>
                ) : (
                    <ul className="mt-2 divide-y divide-slate-100">
                        {education.demos.map((demo) => (
                            <li key={demo.id} className="py-2 text-sm">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-slate-900">
                                        {formatDateTime(demo.scheduled_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}
                                    </span>
                                    <Chip label={demo.status_label} size="small" color={COLORS[demo.status] ?? 'default'} variant="outlined" />
                                </div>
                                {demo.course ? <p className="text-xs text-slate-500">{[demo.course.name, demo.batch?.name].filter(Boolean).join(' · ')}</p> : null}
                                {demo.status === 'scheduled' && can('students.admit') ? (
                                    <div className="mt-1">
                                        <DemoActions demo={demo} />
                                    </div>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}

                {canAdmit ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button size="small" variant="outlined" onClick={() => setScheduling(true)}>
                            Schedule demo
                        </Button>
                        <Button size="small" variant="contained" component={Link} href={`/students/admit?lead=${lead.id}`}>
                            Admit
                        </Button>
                    </div>
                ) : null}
            </CardContent>

            {scheduling ? <ScheduleDemoDialog lead={lead} courses={education.courses} open onClose={() => setScheduling(false)} /> : null}
        </Card>
    );
}

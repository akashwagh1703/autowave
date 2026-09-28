import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import useTenant from '@/hooks/useTenant';
import { WEEKDAYS } from '@/utils/booking';
import { formatPrice } from '@/utils/format';

export default function BatchForm({ form, onSubmit, submitLabel, cancelHref, courses, teachers }) {
    const { currency } = useTenant();
    const course = courses.find((item) => item.id === Number(form.data.course_id));
    const field = (name) => ({
        value: form.data[name] ?? '',
        onChange: (event) => form.setData(name, event.target.value),
        error: Boolean(form.errors[name]),
    });

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-8">
            <section className="grid gap-4 sm:grid-cols-2">
                <TextField select label="Course" required {...field('course_id')} helperText={form.errors.course_id}>
                    {courses.map((item) => (
                        <MenuItem key={item.id} value={item.id}>
                            {item.name}
                        </MenuItem>
                    ))}
                </TextField>
                <TextField
                    label="Batch name"
                    required
                    {...field('name')}
                    helperText={form.errors.name ?? 'e.g. Morning batch, Weekend 2026'}
                    slotProps={{ htmlInput: { maxLength: 120 } }}
                />
                <TextField select label="Teacher" {...field('teacher_tenant_user_id')} helperText={form.errors.teacher_tenant_user_id ?? 'Optional.'} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                    <MenuItem value="">
                        <em>Not assigned</em>
                    </MenuItem>
                    {teachers.map((teacher) => (
                        <MenuItem key={teacher.id} value={teacher.id}>
                            {teacher.name}
                        </MenuItem>
                    ))}
                </TextField>
                <TextField label="Room" {...field('room')} helperText={form.errors.room ?? 'Optional, e.g. Room 2 or Online.'} slotProps={{ htmlInput: { maxLength: 80 } }} />
            </section>

            <section>
                <h2 className="font-semibold text-slate-900">Schedule</h2>
                <p className="mb-3 text-sm text-slate-600">Class days and times, used for attendance and shown to students.</p>
                <ToggleButtonGroup
                    value={form.data.weekdays ?? []}
                    onChange={(_, value) => form.setData('weekdays', value)}
                    size="small"
                    aria-label="Class days"
                    className="flex-wrap"
                >
                    {WEEKDAYS.map((day) => (
                        <ToggleButton key={day.value} value={day.value} aria-label={day.label} className="px-3">
                            {day.short}
                        </ToggleButton>
                    ))}
                </ToggleButtonGroup>
                {form.errors.weekdays ? <p className="mt-1 text-xs text-red-600">{form.errors.weekdays}</p> : null}
                <div className="mt-4 grid gap-4 sm:grid-cols-4">
                    <TextField label="Starts at" type="time" {...field('start_time')} helperText={form.errors.start_time} slotProps={{ inputLabel: { shrink: true } }} />
                    <TextField label="Ends at" type="time" {...field('end_time')} helperText={form.errors.end_time} slotProps={{ inputLabel: { shrink: true } }} />
                    <TextField label="First class" type="date" {...field('starts_on')} helperText={form.errors.starts_on} slotProps={{ inputLabel: { shrink: true } }} />
                    <TextField label="Last class" type="date" {...field('ends_on')} helperText={form.errors.ends_on} slotProps={{ inputLabel: { shrink: true } }} />
                </div>
            </section>

            <section className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Capacity"
                    type="number"
                    {...field('capacity')}
                    helperText={form.errors.capacity ?? 'Optional. Admissions stop when the batch is full.'}
                    slotProps={{ htmlInput: { min: 1, max: 1000 } }}
                />
                <TextField
                    label="Batch fee"
                    type="number"
                    {...field('fee')}
                    helperText={form.errors.fee ?? (course?.fee ? `Leave empty to use the course fee (${formatPrice(course.fee, currency)}).` : 'Leave empty to use the course fee.')}
                    slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                />
                <FormControlLabel
                    control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />}
                    label="Open for admissions"
                    className="sm:col-span-2"
                />
            </section>

            <div className="flex justify-end gap-2">
                <Button component={Link} href={cancelHref} color="inherit">
                    Cancel
                </Button>
                <Button type="submit" variant="contained" disabled={form.processing}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}

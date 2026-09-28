import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import useTenant from '@/hooks/useTenant';

const DURATION_PRESETS = [15, 30, 45, 60, 90, 120];

export default function ServiceForm({ form, onSubmit, submitLabel, cancelHref, categories, resources }) {
    const { currency, resourceLabel } = useTenant();
    const selected = form.data.resource_ids ?? [];
    const toggleResource = (id) =>
        form.setData('resource_ids', selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-6">
            <section className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Name"
                    required
                    autoFocus
                    fullWidth
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    error={Boolean(form.errors.name)}
                    helperText={form.errors.name}
                    slotProps={{ htmlInput: { maxLength: 120 } }}
                    className="sm:col-span-2"
                />
                <TextField
                    select
                    label="Category"
                    value={form.data.service_category_id ?? ''}
                    onChange={(event) => form.setData('service_category_id', event.target.value || null)}
                    error={Boolean(form.errors.service_category_id)}
                    helperText={form.errors.service_category_id}
                    slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
                >
                    <MenuItem value="">
                        <em>No category</em>
                    </MenuItem>
                    {categories.map((category) => (
                        <MenuItem key={category.id} value={category.id}>
                            {category.name}
                        </MenuItem>
                    ))}
                </TextField>
                <TextField
                    label="Price"
                    type="number"
                    required
                    value={form.data.price}
                    onChange={(event) => form.setData('price', event.target.value)}
                    error={Boolean(form.errors.price)}
                    helperText={form.errors.price ?? 'Default price; you can change it on each appointment.'}
                    slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                />
                <div className="sm:col-span-2">
                    <TextField
                        label="Duration"
                        type="number"
                        required
                        value={form.data.duration_minutes}
                        onChange={(event) => form.setData('duration_minutes', event.target.value)}
                        error={Boolean(form.errors.duration_minutes)}
                        helperText={form.errors.duration_minutes ?? 'How long the booking blocks the calendar.'}
                        slotProps={{ htmlInput: { min: 5, max: 720, step: 5 }, input: { endAdornment: <InputAdornment position="end">min</InputAdornment> } }}
                        className="w-44"
                    />
                    <div className="mt-2 flex flex-wrap gap-2" role="group" aria-label="Common durations">
                        {DURATION_PRESETS.map((minutes) => (
                            <button
                                key={minutes}
                                type="button"
                                onClick={() => form.setData('duration_minutes', minutes)}
                                className={`rounded-md border px-2.5 py-1 text-xs ${Number(form.data.duration_minutes) === minutes ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:border-slate-300'}`}
                            >
                                {minutes < 60 ? `${minutes} min` : `${minutes / 60} h`}
                            </button>
                        ))}
                    </div>
                </div>
                <TextField
                    label="Description"
                    multiline
                    minRows={3}
                    fullWidth
                    value={form.data.description ?? ''}
                    onChange={(event) => form.setData('description', event.target.value)}
                    error={Boolean(form.errors.description)}
                    helperText={form.errors.description ?? 'Shown to customers on your website in a later release.'}
                    slotProps={{ htmlInput: { maxLength: 2000 } }}
                    className="sm:col-span-2"
                />
                <FormControlLabel
                    control={<Switch checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />}
                    label="Active — can be booked"
                    className="sm:col-span-2"
                />
            </section>

            {resources.length ? (
                <section>
                    <h2 className="font-semibold text-slate-900">Who offers this service</h2>
                    <p className="text-sm text-slate-600">Only the selected {resourceLabel.plural.toLowerCase()} can be booked for it.</p>
                    {form.errors.resource_ids ? <p className="mt-1 text-sm text-red-600">{form.errors.resource_ids}</p> : null}
                    <div className="mt-2 grid gap-1 sm:grid-cols-2">
                        {resources.map((resource) => (
                            <FormControlLabel
                                key={resource.id}
                                control={<Checkbox checked={selected.includes(resource.id)} onChange={() => toggleResource(resource.id)} />}
                                label={
                                    <span className="flex items-center gap-2">
                                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: resource.color }} />
                                        {resource.name}
                                    </span>
                                }
                            />
                        ))}
                    </div>
                </section>
            ) : null}

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

import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import CloseIcon from '@mui/icons-material/Close';
import { WEEKDAYS } from '@/utils/booking';

const MAX_PER_DAY = 4;

function firstError(errors, prefix) {
    return errors[prefix] ?? Object.entries(errors).find(([key]) => key.startsWith(`${prefix}.`))?.[1];
}

/**
 * Weekly opening hours: each day is closed or has one or more time ranges (split shifts).
 * `value` is the flat server format: [{ weekday, starts_at, ends_at }].
 */
export default function WorkingHoursEditor({ value, onChange, errors = {}, field = 'working_hours', disabled = false }) {
    const rows = value.map((row, index) => ({ ...row, index }));
    const error = firstError(errors, field);

    const update = (index, changes) => onChange(value.map((row, i) => (i === index ? { ...row, ...changes } : row)));
    const remove = (index) => onChange(value.filter((_, i) => i !== index));
    const add = (weekday) => {
        const last = rows.filter((row) => row.weekday === weekday).at(-1);
        onChange([...value, last ? { weekday, starts_at: last.ends_at, ends_at: '23:59' } : { weekday, starts_at: '10:00', ends_at: '19:00' }]);
    };
    const toggleDay = (weekday, open) => (open ? add(weekday) : onChange(value.filter((row) => row.weekday !== weekday)));

    return (
        <div>
            {error ? (
                <Alert severity="error" className="mb-3">
                    {error}
                </Alert>
            ) : null}
            <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                {WEEKDAYS.map((day) => {
                    const windows = rows.filter((row) => row.weekday === day.value);
                    const open = windows.length > 0;

                    return (
                        <li key={day.value} className="flex flex-wrap items-start gap-3 px-3 py-2">
                            <div className="flex w-36 items-center gap-1">
                                <Switch
                                    size="small"
                                    checked={open}
                                    disabled={disabled}
                                    onChange={(event) => toggleDay(day.value, event.target.checked)}
                                    slotProps={{ input: { 'aria-label': `${day.label} open` } }}
                                />
                                <span className={`text-sm ${open ? 'font-medium text-slate-900' : 'text-slate-500'}`}>{day.label}</span>
                            </div>
                            {open ? (
                                <div className="flex flex-1 flex-col gap-2">
                                    {windows.map((row) => (
                                        <div key={row.index} className="flex flex-wrap items-center gap-2">
                                            <TextField
                                                type="time"
                                                size="small"
                                                value={row.starts_at}
                                                disabled={disabled}
                                                onChange={(event) => update(row.index, { starts_at: event.target.value })}
                                                error={Boolean(errors[`${field}.${row.index}`] || errors[`${field}.${row.index}.starts_at`])}
                                                slotProps={{ htmlInput: { 'aria-label': `${day.label} opens`, step: 300 } }}
                                                className="w-32"
                                            />
                                            <span className="text-sm text-slate-500">to</span>
                                            <TextField
                                                type="time"
                                                size="small"
                                                value={row.ends_at}
                                                disabled={disabled}
                                                onChange={(event) => update(row.index, { ends_at: event.target.value })}
                                                error={Boolean(errors[`${field}.${row.index}`] || errors[`${field}.${row.index}.ends_at`])}
                                                slotProps={{ htmlInput: { 'aria-label': `${day.label} closes`, step: 300 } }}
                                                className="w-32"
                                            />
                                            <IconButton size="small" aria-label={`Remove ${day.label} time range`} disabled={disabled} onClick={() => remove(row.index)}>
                                                <CloseIcon fontSize="small" />
                                            </IconButton>
                                            {errors[`${field}.${row.index}`] ? <span className="text-xs text-red-600">{errors[`${field}.${row.index}`]}</span> : null}
                                        </div>
                                    ))}
                                    {windows.length < MAX_PER_DAY && !disabled ? (
                                        <div>
                                            <Button size="small" startIcon={<AddIcon />} onClick={() => add(day.value)}>
                                                Add time range
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <span className="py-1 text-sm text-slate-400">Closed</span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

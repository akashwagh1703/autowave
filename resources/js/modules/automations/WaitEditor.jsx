import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { waitModesFor } from '@/modules/automations/catalog';

const unitLabels = { minutes: 'minutes', hours: 'hours', days: 'days' };

export default function WaitEditor({ config, onChange, catalog, trigger, errors, prefix }) {
    const modes = waitModesFor(catalog, trigger);
    const relative = config.mode === 'before_start' || config.mode === 'after_start';

    return (
        <div className="space-y-2">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                <TextField
                    select
                    size="small"
                    label="Wait"
                    value={modes.some((mode) => mode.key === config.mode) ? config.mode : ''}
                    onChange={(event) => onChange({ ...config, mode: event.target.value })}
                    error={Boolean(errors[`${prefix}.mode`])}
                    helperText={errors[`${prefix}.mode`]}
                    className="sm:w-72"
                >
                    {modes.map((mode) => (
                        <MenuItem key={mode.key} value={mode.key}>
                            {mode.label}
                        </MenuItem>
                    ))}
                </TextField>
                <TextField
                    size="small"
                    type="number"
                    label="Amount"
                    value={config.amount ?? ''}
                    onChange={(event) => onChange({ ...config, amount: event.target.value === '' ? '' : Number(event.target.value) })}
                    error={Boolean(errors[`${prefix}.amount`])}
                    helperText={errors[`${prefix}.amount`]}
                    slotProps={{ htmlInput: { min: relative ? 0 : 1, step: 1 } }}
                    className="sm:w-32"
                />
                <TextField select size="small" label="Unit" value={config.unit ?? 'hours'} onChange={(event) => onChange({ ...config, unit: event.target.value })} className="sm:w-36">
                    {catalog.waitUnits.map((unit) => (
                        <MenuItem key={unit} value={unit}>
                            {unitLabels[unit] ?? unit}
                        </MenuItem>
                    ))}
                </TextField>
            </div>
            <p className="text-xs text-slate-500">
                {relative
                    ? 'Counted from the appointment start, and moved automatically if the appointment is rescheduled. If that time has already passed, the next step runs straight away.'
                    : `Waits up to ${catalog.limits.max_wait_days} days. The next step runs within a minute of the time.`}
            </p>
        </div>
    );
}

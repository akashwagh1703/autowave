import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import AddIcon from '@mui/icons-material/Add';
import CloseIcon from '@mui/icons-material/Close';
import useTenant from '@/hooks/useTenant';
import { WEEKDAYS } from '@/utils/booking';

const MAX_RATES = 6;
const PRESETS = [
    { label: 'Peak hours', weekdays: [1, 2, 3, 4, 5], from: '18:00', to: '23:00' },
    { label: 'Weekend', weekdays: [6, 7], from: '06:00', to: '24:00' },
];

/**
 * Hourly rate plus extra rates (peak hours, weekends) for bookings without a service.
 * The first rate covering a minute wins; otherwise the hourly rate applies.
 */
export default function RatesEditor({ hourlyRate, rates, onHourlyRateChange, onRatesChange, errors = {} }) {
    const { currency } = useTenant();
    const money = { input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> }, htmlInput: { min: 0, step: '0.01', inputMode: 'decimal' } };

    const update = (index, changes) => onRatesChange(rates.map((rate, i) => (i === index ? { ...rate, ...changes } : rate)));
    const remove = (index) => onRatesChange(rates.filter((_, i) => i !== index));
    const add = () => {
        const preset = PRESETS[rates.length] ?? { label: '', weekdays: [1, 2, 3, 4, 5, 6, 7], from: '18:00', to: '22:00' };
        onRatesChange([...rates, { ...preset, hourly_rate: hourlyRate || '' }]);
    };
    const fieldError = (index, field) => errors[`rates.${index}.${field}`];

    return (
        <div className="space-y-4">
            <TextField
                label="Hourly rate"
                type="number"
                value={hourlyRate ?? ''}
                onChange={(event) => onHourlyRateChange(event.target.value)}
                error={Boolean(errors.hourly_rate)}
                helperText={errors.hourly_rate ?? 'Leave empty to set prices by hand.'}
                slotProps={money}
                className="w-full sm:w-64"
            />

            {errors.rates ? <p className="text-sm text-red-600">{errors.rates}</p> : null}

            {rates.map((rate, index) => (
                <div key={index} className="space-y-3 rounded-lg border border-slate-200 p-3">
                    <div className="flex flex-wrap items-start gap-3">
                        <TextField
                            label="Name"
                            size="small"
                            value={rate.label}
                            onChange={(event) => update(index, { label: event.target.value })}
                            error={Boolean(fieldError(index, 'label'))}
                            helperText={fieldError(index, 'label')}
                            slotProps={{ htmlInput: { maxLength: 40 } }}
                            className="w-44"
                        />
                        <TextField
                            label="From"
                            type="time"
                            size="small"
                            value={rate.from}
                            onChange={(event) => update(index, { from: event.target.value })}
                            error={Boolean(fieldError(index, 'from'))}
                            helperText={fieldError(index, 'from')}
                            slotProps={{ inputLabel: { shrink: true }, htmlInput: { step: 300 } }}
                            className="w-32"
                        />
                        <TextField
                            label="To"
                            type="time"
                            size="small"
                            value={rate.to === '24:00' ? '23:59' : rate.to}
                            onChange={(event) => update(index, { to: event.target.value === '23:59' ? '24:00' : event.target.value })}
                            slotProps={{ inputLabel: { shrink: true }, htmlInput: { step: 300 } }}
                            className="w-32"
                        />
                        <TextField
                            label="Hourly rate"
                            type="number"
                            size="small"
                            value={rate.hourly_rate}
                            onChange={(event) => update(index, { hourly_rate: event.target.value })}
                            error={Boolean(fieldError(index, 'hourly_rate'))}
                            helperText={fieldError(index, 'hourly_rate')}
                            slotProps={money}
                            className="w-40"
                        />
                        <IconButton aria-label={`Remove ${rate.label || 'rate'}`} onClick={() => remove(index)} className="ml-auto">
                            <CloseIcon fontSize="small" />
                        </IconButton>
                    </div>
                    <div>
                        <ToggleButtonGroup
                            size="small"
                            value={rate.weekdays}
                            onChange={(_, weekdays) => update(index, { weekdays })}
                            aria-label={`Days for ${rate.label || 'rate'}`}
                        >
                            {WEEKDAYS.map((day) => (
                                <ToggleButton key={day.value} value={day.value} aria-label={day.label}>
                                    {day.short}
                                </ToggleButton>
                            ))}
                        </ToggleButtonGroup>
                        {fieldError(index, 'weekdays') ? <p className="mt-1 text-xs text-red-600">{fieldError(index, 'weekdays')}</p> : null}
                    </div>
                </div>
            ))}

            {rates.length < MAX_RATES ? (
                <Button size="small" startIcon={<AddIcon />} onClick={add}>
                    Add rate (peak hours, weekend…)
                </Button>
            ) : null}
        </div>
    );
}

import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import useTenant from '@/hooks/useTenant';
import { addMonths, sumAmounts } from '@/utils/education';
import { formatPrice } from '@/utils/format';

/** Editable instalment rows (due date + amount) that must add up to `net`. */
export default function InstalmentsEditor({ rows, onChange, net, errors = {}, max = 24 }) {
    const { currency } = useTenant();
    const sum = sumAmounts(rows);
    const matches = sum === net;

    const update = (index, changes) => onChange(rows.map((row, i) => (i === index ? { ...row, ...changes } : row)));
    const remove = (index) => onChange(rows.filter((_, i) => i !== index));
    const add = () => {
        const last = rows[rows.length - 1];
        const remaining = (Number(net) - Number(sum)).toFixed(2);
        onChange([...rows, { due_on: last ? addMonths(last.due_on, 1) : '', amount: Number(remaining) > 0 ? remaining : '' }]);
    };

    return (
        <div>
            <ul className="space-y-2">
                {rows.map((row, index) => (
                    <li key={index} className="flex items-start gap-2">
                        <span className="w-6 pt-2 text-right text-sm text-slate-500">{index + 1}.</span>
                        <TextField
                            type="date"
                            size="small"
                            label="Due on"
                            value={row.due_on}
                            onChange={(event) => update(index, { due_on: event.target.value })}
                            error={Boolean(errors[`instalments.${index}.due_on`])}
                            helperText={errors[`instalments.${index}.due_on`]}
                            slotProps={{ inputLabel: { shrink: true } }}
                        />
                        <TextField
                            type="number"
                            size="small"
                            label="Amount"
                            value={row.amount}
                            onChange={(event) => update(index, { amount: event.target.value })}
                            error={Boolean(errors[`instalments.${index}.amount`])}
                            helperText={errors[`instalments.${index}.amount`]}
                            slotProps={{ htmlInput: { min: 0.01, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                            className="w-44"
                        />
                        <IconButton size="small" aria-label={`Remove instalment ${index + 1}`} onClick={() => remove(index)} className="mt-1">
                            <DeleteOutlineIcon fontSize="small" />
                        </IconButton>
                    </li>
                ))}
            </ul>
            <div className="mt-2 flex flex-wrap items-center gap-3">
                <Button size="small" startIcon={<AddIcon />} onClick={add} disabled={rows.length >= max}>
                    Add instalment
                </Button>
                <span className={`text-sm ${matches ? 'text-slate-600' : 'text-red-700'}`}>
                    Total {formatPrice(sum, currency)} of {formatPrice(net, currency)}
                    {matches ? '' : ` · ${Number(sum) > Number(net) ? 'over' : 'short'} by ${formatPrice(Math.abs(Number(net) - Number(sum)).toFixed(2), currency)}`}
                </span>
            </div>
            {errors.instalments ? <p className="mt-1 text-sm text-red-600">{errors.instalments}</p> : null}
        </div>
    );
}

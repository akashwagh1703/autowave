import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import CloseIcon from '@mui/icons-material/Close';
import { defaultRule, fieldsFor, operatorsFor, VALUELESS_OPERATORS } from '@/modules/automations/catalog';

function ValueInput({ field, operator, value, onChange, error }) {
    if (!field || VALUELESS_OPERATORS.includes(operator)) {
        return <div className="hidden sm:block sm:flex-1" />;
    }

    const common = { size: 'small', label: 'Value', error: Boolean(error), helperText: error, className: 'sm:flex-1' };

    if (field.type === 'enum') {
        const multiple = operator === 'in' || operator === 'not_in';
        const current = multiple ? (Array.isArray(value) ? value : value ? [value] : []) : Array.isArray(value) ? (value[0] ?? '') : (value ?? '');

        return (
            <TextField
                {...common}
                select
                value={current}
                onChange={(event) => onChange(event.target.value)}
                slotProps={{ select: { multiple, renderValue: multiple ? (selected) => selected.map((item) => field.options.find((option) => option.value === item)?.label ?? item).join(', ') : undefined } }}
            >
                {(field.options ?? []).map((option) => (
                    <MenuItem key={option.value} value={option.value}>
                        {option.label}
                    </MenuItem>
                ))}
            </TextField>
        );
    }

    return (
        <TextField
            {...common}
            type={field.type === 'number' ? 'number' : 'text'}
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
            slotProps={{ htmlInput: field.type === 'number' ? { min: 0, step: 'any' } : { maxLength: field.type === 'tags' ? 50 : 150 } }}
        />
    );
}

export default function ConditionEditor({ config, onChange, catalog, trigger, errors, prefix }) {
    const fields = fieldsFor(catalog, trigger);
    const rules = config.rules ?? [];
    const setRule = (index, changes) => onChange({ ...config, rules: rules.map((rule, i) => (i === index ? { ...rule, ...changes } : rule)) });
    const limit = catalog.limits.rules;

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2 text-sm text-slate-700">
                Continue only if
                <TextField select size="small" value={config.match ?? 'all'} onChange={(event) => onChange({ ...config, match: event.target.value })} aria-label="Match">
                    <MenuItem value="all">all</MenuItem>
                    <MenuItem value="any">any</MenuItem>
                </TextField>
                of these are true:
            </div>

            {errors[`${prefix}.rules`] ? <p className="text-sm text-red-600">{errors[`${prefix}.rules`]}</p> : null}

            {rules.map((rule, index) => {
                const field = fields.find((item) => item.key === rule.field);
                const operators = operatorsFor(catalog, field);
                const key = `${prefix}.rules.${index}`;

                return (
                    <div key={index} className="flex flex-col gap-2 rounded-lg bg-slate-50 p-2 sm:flex-row sm:items-start">
                        <TextField
                            select
                            size="small"
                            label="Field"
                            value={field ? rule.field : ''}
                            onChange={(event) => {
                                const next = fields.find((item) => item.key === event.target.value);
                                setRule(index, { field: next.key, operator: operatorsFor(catalog, next)[0]?.value ?? '', value: '' });
                            }}
                            error={Boolean(errors[`${key}.field`])}
                            helperText={errors[`${key}.field`]}
                            className="sm:w-56"
                        >
                            {fields.map((item) => (
                                <MenuItem key={item.key} value={item.key}>
                                    {item.label}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField
                            select
                            size="small"
                            label="Check"
                            value={operators.some((operator) => operator.value === rule.operator) ? rule.operator : ''}
                            onChange={(event) => setRule(index, { operator: event.target.value, value: ['in', 'not_in'].includes(event.target.value) ? [] : '' })}
                            error={Boolean(errors[`${key}.operator`])}
                            helperText={errors[`${key}.operator`]}
                            className="sm:w-44"
                        >
                            {operators.map((operator) => (
                                <MenuItem key={operator.value} value={operator.value}>
                                    {operator.label}
                                </MenuItem>
                            ))}
                        </TextField>
                        <ValueInput
                            field={field}
                            operator={rule.operator}
                            value={rule.value}
                            onChange={(value) => setRule(index, { value })}
                            error={errors[`${key}.value`] ?? errors[`${key}.value.0`]}
                        />
                        <IconButton
                            size="small"
                            aria-label="Remove rule"
                            disabled={rules.length === 1}
                            onClick={() => onChange({ ...config, rules: rules.filter((_, i) => i !== index) })}
                        >
                            <CloseIcon fontSize="small" />
                        </IconButton>
                    </div>
                );
            })}

            <Button size="small" startIcon={<AddIcon />} disabled={rules.length >= limit || fields.length === 0} onClick={() => onChange({ ...config, rules: [...rules, defaultRule(catalog, trigger)] })}>
                Add rule
            </Button>
        </div>
    );
}

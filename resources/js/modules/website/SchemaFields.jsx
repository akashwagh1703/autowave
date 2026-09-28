import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import AddIcon from '@mui/icons-material/Add';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlined';

/**
 * Renders a website section's fields from their definitions (config/website.php, sent by the
 * server) so new section types need no new editor code. Errors are keyed like the server's:
 * `config.<field>` and `config.<list>.<index>.<field>`.
 */
function helper(field, error, value) {
    if (error) {
        return error;
    }

    const count = field.max && typeof value === 'string' && value.length > field.max * 0.8 ? `${value.length}/${field.max}` : null;

    return [field.help, count].filter(Boolean).join(' · ') || undefined;
}

function ScalarField({ field, value, onChange, error, disabled }) {
    switch (field.type) {
        case 'boolean':
            return (
                <div>
                    <FormControlLabel control={<Switch checked={Boolean(value)} disabled={disabled} onChange={(event) => onChange(event.target.checked)} />} label={field.label} />
                    {field.help ? <p className="text-sm text-slate-600">{field.help}</p> : null}
                    {error ? <p className="text-sm text-red-600">{error}</p> : null}
                </div>
            );
        case 'select':
            return (
                <TextField select label={field.label} fullWidth disabled={disabled} value={value ?? ''} onChange={(event) => onChange(event.target.value)} error={Boolean(error)} helperText={helper(field, error)}>
                    {field.options.map((option) => (
                        <MenuItem key={option.value} value={option.value}>
                            {option.label}
                        </MenuItem>
                    ))}
                </TextField>
            );
        default:
            return (
                <TextField
                    label={field.label}
                    fullWidth
                    required={field.required}
                    disabled={disabled}
                    multiline={field.type === 'textarea'}
                    minRows={field.type === 'textarea' ? (field.rows ?? 3) : undefined}
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                    error={Boolean(error)}
                    helperText={helper(field, error, value)}
                    slotProps={{ htmlInput: { maxLength: field.max } }}
                />
            );
    }
}

function ListField({ field, value, onChange, errors, path, disabled }) {
    const items = Array.isArray(value) ? value : [];
    const blank = () => Object.fromEntries(field.fields.map((sub) => [sub.key, sub.type === 'boolean' ? false : '']));
    const update = (index, key, next) => onChange(items.map((item, i) => (i === index ? { ...item, [key]: next } : item)));
    const move = (index, offset) => {
        const next = [...items];
        [next[index], next[index + offset]] = [next[index + offset], next[index]];
        onChange(next);
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <h3 className="font-semibold text-slate-900">{field.label}</h3>
                <span className="text-sm text-slate-500">
                    {items.length} / {field.max}
                </span>
            </div>
            {errors[path] ? <p className="text-sm text-red-600">{errors[path]}</p> : null}
            {items.length === 0 ? <p className="text-sm text-slate-600">Nothing added yet.</p> : null}
            {items.map((item, index) => (
                <Card key={index} variant="outlined">
                    <CardContent className="space-y-3">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-sm font-medium text-slate-700">
                                {field.item_label ?? 'Item'} {index + 1}
                            </span>
                            {!disabled ? (
                                <div className="flex">
                                    <IconButton size="small" aria-label="Move up" disabled={index === 0} onClick={() => move(index, -1)}>
                                        <ArrowUpwardIcon fontSize="small" />
                                    </IconButton>
                                    <IconButton size="small" aria-label="Move down" disabled={index === items.length - 1} onClick={() => move(index, 1)}>
                                        <ArrowDownwardIcon fontSize="small" />
                                    </IconButton>
                                    <IconButton size="small" aria-label={`Remove ${field.item_label ?? 'item'} ${index + 1}`} onClick={() => onChange(items.filter((_, i) => i !== index))}>
                                        <DeleteOutlineIcon fontSize="small" />
                                    </IconButton>
                                </div>
                            ) : null}
                        </div>
                        {field.fields.map((sub) => (
                            <ScalarField
                                key={sub.key}
                                field={sub}
                                value={item[sub.key]}
                                disabled={disabled}
                                onChange={(next) => update(index, sub.key, next)}
                                error={errors[`${path}.${index}.${sub.key}`]}
                            />
                        ))}
                    </CardContent>
                </Card>
            ))}
            {!disabled ? (
                <Button startIcon={<AddIcon />} onClick={() => onChange([...items, blank()])} disabled={items.length >= field.max}>
                    Add {(field.item_label ?? 'item').toLowerCase()}
                </Button>
            ) : null}
        </div>
    );
}

export default function SchemaFields({ fields, values, onChange, errors, disabled }) {
    return (
        <div className="space-y-5">
            {fields.map((field) =>
                field.type === 'list' ? (
                    <ListField
                        key={field.key}
                        field={field}
                        value={values[field.key]}
                        onChange={(next) => onChange(field.key, next)}
                        errors={errors}
                        path={`config.${field.key}`}
                        disabled={disabled}
                    />
                ) : (
                    <ScalarField
                        key={field.key}
                        field={field}
                        value={values[field.key]}
                        onChange={(next) => onChange(field.key, next)}
                        error={errors[`config.${field.key}`]}
                        disabled={disabled}
                    />
                ),
            )}
        </div>
    );
}

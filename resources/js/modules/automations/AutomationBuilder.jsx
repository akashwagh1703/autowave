import { Link } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import ListSubheader from '@mui/material/ListSubheader';
import MenuItem from '@mui/material/MenuItem';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import BoltIcon from '@mui/icons-material/Bolt';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import FilterAltIcon from '@mui/icons-material/FilterAltOutlined';
import HourglassEmptyIcon from '@mui/icons-material/HourglassEmpty';
import PlayArrowIcon from '@mui/icons-material/PlayArrowOutlined';
import ActionEditor from '@/modules/automations/ActionEditor';
import ConditionEditor from '@/modules/automations/ConditionEditor';
import WaitEditor from '@/modules/automations/WaitEditor';
import { newStep, triggerOf } from '@/modules/automations/catalog';

const stepMeta = {
    condition: { label: 'Condition', icon: FilterAltIcon, color: 'text-amber-600' },
    wait: { label: 'Wait', icon: HourglassEmptyIcon, color: 'text-sky-600' },
    action: { label: 'Action', icon: PlayArrowIcon, color: 'text-emerald-600' },
};

/**
 * Trigger → steps (condition / wait / action) editor. `form` is an Inertia useForm with
 * name, description, trigger, is_active, once_per_subject and steps (each with a client `_key`).
 */
export default function AutomationBuilder({ form, catalog, onSubmit, submitLabel, cancelHref }) {
    const { data, errors } = form;
    const trigger = triggerOf(catalog, data.trigger);
    const steps = data.steps;
    const groups = [...new Set(catalog.triggers.map((item) => item.group))];
    const maxSteps = catalog.limits.steps;

    const setStep = (index, step) => form.setData('steps', steps.map((item, i) => (i === index ? step : item)));
    const removeStep = (index) => form.setData('steps', steps.filter((_, i) => i !== index));
    const moveStep = (index, offset) => {
        const next = [...steps];
        [next[index], next[index + offset]] = [next[index + offset], next[index]];
        form.setData('steps', next);
    };
    const addStep = (type) => form.setData('steps', [...steps, newStep(type, catalog, trigger)]);

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-5">
            <Card variant="outlined">
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <TextField
                        label="Name"
                        required
                        fullWidth
                        value={data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={Boolean(errors.name)}
                        helperText={errors.name}
                        slotProps={{ htmlInput: { maxLength: 120 } }}
                        className="sm:col-span-2"
                    />
                    <TextField
                        label="Description"
                        fullWidth
                        value={data.description ?? ''}
                        onChange={(event) => form.setData('description', event.target.value)}
                        error={Boolean(errors.description)}
                        helperText={errors.description ?? 'Optional — a note for your team.'}
                        slotProps={{ htmlInput: { maxLength: 500 } }}
                        className="sm:col-span-2"
                    />
                    <div className="flex flex-col gap-1 sm:col-span-2 sm:flex-row sm:items-center sm:gap-6">
                        <FormControlLabel control={<Switch checked={data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />} label="On" />
                        <FormControlLabel
                            control={<Switch checked={data.once_per_subject} onChange={(event) => form.setData('once_per_subject', event.target.checked)} />}
                            label={`Run only once per ${trigger?.subject ?? 'record'}`}
                        />
                    </div>
                </CardContent>
            </Card>

            <ol className="space-y-3">
                <li>
                    <Card variant="outlined" className="border-brand-200">
                        <CardContent className="space-y-2">
                            <div className="flex items-center gap-2 font-semibold text-slate-900">
                                <BoltIcon className="text-brand-600" fontSize="small" />
                                When
                            </div>
                            <TextField
                                select
                                size="small"
                                label="Trigger"
                                value={trigger ? data.trigger : ''}
                                onChange={(event) => form.setData('trigger', event.target.value)}
                                error={Boolean(errors.trigger)}
                                helperText={errors.trigger ?? trigger?.description}
                                className="w-full sm:w-96"
                            >
                                {groups.flatMap((group) => [
                                    <ListSubheader key={`group-${group}`}>{group}</ListSubheader>,
                                    ...catalog.triggers
                                        .filter((item) => item.group === group)
                                        .map((item) => (
                                            <MenuItem key={item.key} value={item.key}>
                                                {item.label}
                                            </MenuItem>
                                        )),
                                ])}
                            </TextField>
                        </CardContent>
                    </Card>
                </li>

                {steps.map((step, index) => {
                    const meta = stepMeta[step.type];
                    const Icon = meta.icon;
                    const prefix = `steps.${index}`;

                    return (
                        <li key={step._key}>
                            <Card variant="outlined" className={errors[`${prefix}.type`] ? 'border-red-300' : ''}>
                                <CardContent className="space-y-3">
                                    <div className="flex items-center gap-2">
                                        <span className="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{index + 1}</span>
                                        <Icon className={meta.color} fontSize="small" />
                                        <span className="font-semibold text-slate-900">{meta.label}</span>
                                        <span className="flex-1" />
                                        <Tooltip title="Move up">
                                            <span>
                                                <IconButton size="small" aria-label="Move step up" disabled={index === 0} onClick={() => moveStep(index, -1)}>
                                                    <ArrowUpwardIcon fontSize="small" />
                                                </IconButton>
                                            </span>
                                        </Tooltip>
                                        <Tooltip title="Move down">
                                            <span>
                                                <IconButton size="small" aria-label="Move step down" disabled={index === steps.length - 1} onClick={() => moveStep(index, 1)}>
                                                    <ArrowDownwardIcon fontSize="small" />
                                                </IconButton>
                                            </span>
                                        </Tooltip>
                                        <Tooltip title="Remove step">
                                            <IconButton size="small" aria-label="Remove step" onClick={() => removeStep(index)}>
                                                <DeleteOutlineIcon fontSize="small" />
                                            </IconButton>
                                        </Tooltip>
                                    </div>
                                    {errors[`${prefix}.type`] ? <p className="text-sm text-red-600">{errors[`${prefix}.type`]}</p> : null}

                                    {step.type === 'condition' ? (
                                        <ConditionEditor config={step.config} onChange={(config) => setStep(index, { ...step, config })} catalog={catalog} trigger={trigger} errors={errors} prefix={`${prefix}.config`} />
                                    ) : null}
                                    {step.type === 'wait' ? (
                                        <WaitEditor config={step.config} onChange={(config) => setStep(index, { ...step, config })} catalog={catalog} trigger={trigger} errors={errors} prefix={`${prefix}.config`} />
                                    ) : null}
                                    {step.type === 'action' ? (
                                        <ActionEditor step={step} onChange={(next) => setStep(index, next)} catalog={catalog} trigger={trigger} errors={errors} prefix={prefix} />
                                    ) : null}
                                </CardContent>
                            </Card>
                        </li>
                    );
                })}
            </ol>

            {errors.steps ? <Alert severity="error">{errors.steps}</Alert> : null}

            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm text-slate-600">Add a step:</span>
                {['condition', 'wait', 'action'].map((type) => {
                    const Icon = stepMeta[type].icon;

                    return (
                        <Button key={type} variant="outlined" size="small" startIcon={<Icon />} disabled={!trigger || steps.length >= maxSteps} onClick={() => addStep(type)}>
                            {stepMeta[type].label}
                        </Button>
                    );
                })}
            </div>
            <p className="text-xs text-slate-500">
                Steps run top to bottom. A condition that is not met stops the run. Editing an automation does not change runs already in progress.
            </p>

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

/** Builder data without the client-only step keys. */
export function toPayload(data) {
    return { ...data, steps: data.steps.map(({ _key, ...step }) => step) };
}

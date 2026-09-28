import Alert from '@mui/material/Alert';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { useRef } from 'react';
import { actionsFor, defaultConfig, variablesFor } from '@/modules/automations/catalog';

/** A message box with clickable {{variable}} chips that insert at the cursor. */
function MessageField({ label, value, onChange, error, helperText, variables, maxLength, multiline = true }) {
    const input = useRef(null);

    const insert = (key) => {
        const token = `{{${key}}}`;
        const element = input.current;
        const text = value ?? '';

        if (!element || element.selectionStart === undefined) {
            onChange(`${text}${text && !text.endsWith(' ') ? ' ' : ''}${token}`);

            return;
        }

        const start = element.selectionStart;
        const end = element.selectionEnd;
        onChange(text.slice(0, start) + token + text.slice(end));

        requestAnimationFrame(() => {
            element.focus();
            element.setSelectionRange(start + token.length, start + token.length);
        });
    };

    return (
        <div>
            <TextField
                label={label}
                fullWidth
                size="small"
                multiline={multiline}
                minRows={multiline ? 3 : undefined}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                error={Boolean(error)}
                helperText={error ?? helperText}
                inputRef={input}
                slotProps={{ htmlInput: { maxLength } }}
            />
            {variables.length ? (
                <div className="mt-1.5 flex flex-wrap items-center gap-1" aria-label={`Insert into ${label.toLowerCase()}`}>
                    <span className="text-xs text-slate-500">Insert:</span>
                    {variables.map((variable) => (
                        <Chip key={variable.key} size="small" variant="outlined" label={variable.label} onClick={() => insert(variable.key)} />
                    ))}
                </div>
            ) : null}
        </div>
    );
}

export default function ActionEditor({ step, onChange, catalog, trigger, errors, prefix }) {
    const actions = actionsFor(catalog, trigger);
    const variables = variablesFor(catalog, trigger);
    const config = step.config ?? {};
    const set = (changes) => onChange({ ...step, config: { ...config, ...changes } });
    const error = (key) => errors[`${prefix}.config.${key}`];
    const limits = catalog.limits;
    const channel = step.action === 'send_whatsapp' ? catalog.channels.whatsapp : step.action === 'send_email' ? catalog.channels.email : null;

    return (
        <div className="space-y-3">
            <TextField
                select
                size="small"
                label="Action"
                value={actions.some((action) => action.key === step.action) ? step.action : ''}
                onChange={(event) => onChange({ ...step, action: event.target.value, config: defaultConfig(event.target.value, catalog) })}
                error={Boolean(errors[`${prefix}.action`])}
                helperText={errors[`${prefix}.action`]}
                className="w-full sm:w-72"
            >
                {actions.map((action) => (
                    <MenuItem key={action.key} value={action.key}>
                        {action.label}
                    </MenuItem>
                ))}
            </TextField>

            {channel?.simulated ? (
                <Alert severity="info" variant="outlined">
                    {channel.label} is not connected yet. Messages are recorded on the timeline as simulated and not delivered.
                </Alert>
            ) : null}

            {step.action === 'send_whatsapp' ? (
                <MessageField label="Message" value={config.message} onChange={(message) => set({ message })} error={error('message')} variables={variables} maxLength={limits.message} />
            ) : null}

            {step.action === 'send_email' || step.action === 'send_notification' ? (
                <>
                    {step.action === 'send_notification' ? (
                        <TextField select size="small" label="Send to" value={config.recipients ?? 'owners'} onChange={(event) => set({ recipients: event.target.value })} error={Boolean(error('recipients'))} helperText={error('recipients') ?? 'Notifications go by email.'} className="w-full sm:w-72">
                            <MenuItem value="owners">Business owners</MenuItem>
                            <MenuItem value="assignee">The lead’s assignee (owners if unassigned)</MenuItem>
                        </TextField>
                    ) : null}
                    <MessageField label="Subject" multiline={false} value={config.subject} onChange={(subject) => set({ subject })} error={error('subject')} variables={variables} maxLength={limits.subject} />
                    <MessageField label="Message" value={config.message} onChange={(message) => set({ message })} error={error('message')} variables={variables} maxLength={limits.message} />
                </>
            ) : null}

            {step.action === 'create_task' ? (
                <div className="grid gap-3 sm:grid-cols-[1fr_12rem]">
                    <MessageField label="Task" multiline={false} value={config.title} onChange={(title) => set({ title })} error={error('title')} helperText="Added to the timeline; open leads get it as their next follow-up." variables={variables} maxLength={150} />
                    <TextField
                        size="small"
                        type="number"
                        label="Due in (hours)"
                        value={config.due_in_hours ?? 0}
                        onChange={(event) => set({ due_in_hours: event.target.value === '' ? '' : Number(event.target.value) })}
                        error={Boolean(error('due_in_hours'))}
                        helperText={error('due_in_hours') ?? '0 = right away'}
                        slotProps={{ htmlInput: { min: 0, max: limits.task_due_hours } }}
                    />
                </div>
            ) : null}

            {step.action === 'assign_lead' ? (
                <div className="flex flex-col gap-3 sm:flex-row">
                    <TextField select size="small" label="Assign" value={config.mode ?? 'auto'} onChange={(event) => set({ mode: event.target.value, tenant_user_id: event.target.value === 'member' ? (catalog.members[0]?.value ?? '') : undefined })} className="sm:w-72">
                        <MenuItem value="auto">Using the lead assignment rules</MenuItem>
                        <MenuItem value="member" disabled={!catalog.members.length}>
                            To a team member
                        </MenuItem>
                    </TextField>
                    {config.mode === 'member' ? (
                        <TextField select size="small" label="Team member" value={config.tenant_user_id ?? ''} onChange={(event) => set({ tenant_user_id: event.target.value })} error={Boolean(error('tenant_user_id'))} helperText={error('tenant_user_id')} className="sm:w-72">
                            {catalog.members.map((member) => (
                                <MenuItem key={member.value} value={member.value}>
                                    {member.label}
                                </MenuItem>
                            ))}
                        </TextField>
                    ) : null}
                </div>
            ) : null}

            {step.action === 'update_lead' ? (
                <TextField select size="small" label="Move to stage" value={config.stage ?? ''} onChange={(event) => set({ stage: event.target.value })} error={Boolean(error('stage'))} helperText={error('stage')} className="w-full sm:w-72">
                    {catalog.stages.map((stage) => (
                        <MenuItem key={stage.value} value={stage.value}>
                            {stage.label}
                        </MenuItem>
                    ))}
                </TextField>
            ) : null}

            {step.action === 'update_customer' ? (
                <TextField size="small" label="Tag" value={config.tag ?? ''} onChange={(event) => set({ tag: event.target.value })} error={Boolean(error('tag'))} helperText={error('tag') ?? 'Added to the customer (the lead’s customer, if converted).'} slotProps={{ htmlInput: { maxLength: 50 } }} className="w-full sm:w-72" />
            ) : null}
        </div>
    );
}

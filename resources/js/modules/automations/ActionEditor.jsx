import Alert from '@mui/material/Alert';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { useRef } from 'react';
import WriteWithAi from '@/modules/ai/WriteWithAi';
import { actionsFor, defaultConfig, variablesFor } from '@/modules/automations/catalog';

/**
 * A message box with clickable {{variable}} chips that insert at the cursor. With `ai` ({ trigger, channel }),
 * a "Write with AI" button drafts the text.
 */
function MessageField({ label, value, onChange, error, helperText, variables, maxLength, multiline = true, ai = null }) {
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
            {variables.length || ai ? (
                <div className="mt-1.5 flex flex-wrap items-center gap-1" aria-label={`Insert into ${label.toLowerCase()}`}>
                    {variables.length ? <span className="text-xs text-slate-500">Insert:</span> : null}
                    {variables.map((variable) => (
                        <Chip key={variable.key} size="small" variant="outlined" label={variable.label} onClick={() => insert(variable.key)} />
                    ))}
                    {ai ? (
                        <WriteWithAi
                            kind="automation_message"
                            title={`Write the ${label.toLowerCase()} with AI`}
                            context={() => ({ trigger: ai.trigger, channel: ai.channel, current: value || null, placeholders: variables.map((variable) => variable.key) })}
                            onUse={onChange}
                        />
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

const templateValue = (template) => (template ? `${template.name}|${template.language}` : '');

/** Free text, or an approved WhatsApp template with one field per {{n}} variable. */
function WhatsAppFields({ step, onChange, catalog, error, variables, limits, ai }) {
    const config = step.config ?? {};
    const templates = catalog.templates ?? [];
    const isTemplate = config.mode === 'template';
    const selected = isTemplate ? templates.find((template) => templateValue(template) === templateValue(config.template)) : null;
    const params = config.params ?? [];

    const switchMode = (mode) => {
        if (mode === 'template') {
            const first = templates[0];
            onChange({ ...step, config: { mode: 'template', template: first ? { name: first.name, language: first.language } : { name: '', language: '' }, params: Array(first?.variables ?? 0).fill('') } });

            return;
        }

        onChange({ ...step, config: { message: '' } });
    };

    const chooseTemplate = (value) => {
        const template = templates.find((item) => templateValue(item) === value);

        if (template) {
            onChange({ ...step, config: { mode: 'template', template: { name: template.name, language: template.language }, params: Array(template.variables).fill('') } });
        }
    };

    const setParam = (index, value) => {
        const next = [...params];
        next[index] = value;
        onChange({ ...step, config: { ...config, params: next } });
    };

    return (
        <>
            <TextField select size="small" label="Send" value={isTemplate ? 'template' : 'text'} onChange={(event) => switchMode(event.target.value)} className="w-full sm:w-72">
                <MenuItem value="text">A free-text message</MenuItem>
                <MenuItem value="template" disabled={!templates.length}>
                    An approved template{templates.length ? '' : ' (none synced)'}
                </MenuItem>
            </TextField>

            {!isTemplate ? (
                <>
                    {catalog.channels.whatsapp?.window ? (
                        <Alert severity="warning" variant="outlined">
                            WhatsApp only allows free text within 24 hours of the contact’s last message. Outside that window this step is skipped — use an approved template for first contact and reminders.
                        </Alert>
                    ) : null}
                    <MessageField label="Message" value={config.message} onChange={(message) => onChange({ ...step, config: { message } })} error={error('message')} variables={variables} maxLength={limits.message} ai={ai} />
                </>
            ) : (
                <>
                    <TextField
                        select
                        size="small"
                        label="Template"
                        value={selected ? templateValue(selected) : ''}
                        onChange={(event) => chooseTemplate(event.target.value)}
                        error={Boolean(error('template') ?? error('template.name'))}
                        helperText={error('template') ?? error('template.name') ?? 'Templates are synced from WhatsApp in Settings → Messaging.'}
                        className="w-full sm:w-96"
                    >
                        {templates.map((template) => (
                            <MenuItem key={templateValue(template)} value={templateValue(template)}>
                                {template.name} ({template.language})
                            </MenuItem>
                        ))}
                    </TextField>
                    {selected ? (
                        <>
                            <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm whitespace-pre-line text-slate-700">{selected.body}</p>
                            {Array.from({ length: selected.variables }, (_, index) => (
                                <MessageField
                                    key={index}
                                    label={`Variable {{${index + 1}}}`}
                                    multiline={false}
                                    value={params[index] ?? ''}
                                    onChange={(value) => setParam(index, value)}
                                    error={error(`params.${index}`)}
                                    variables={variables}
                                    maxLength={500}
                                />
                            ))}
                        </>
                    ) : null}
                    {error('params') ? <p className="text-sm text-red-600">{error('params')}</p> : null}
                </>
            )}
        </>
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
    const ai = { trigger: trigger?.label ?? null, channel: step.action === 'send_notification' ? 'email to the team' : (channel?.label ?? null) };

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

            {step.action === 'send_whatsapp' ? <WhatsAppFields step={step} onChange={onChange} catalog={catalog} error={error} variables={variables} limits={limits} ai={ai} /> : null}

            {step.action === 'send_email' || step.action === 'send_notification' ? (
                <>
                    {step.action === 'send_notification' ? (
                        <TextField select size="small" label="Send to" value={config.recipients ?? 'owners'} onChange={(event) => set({ recipients: event.target.value })} error={Boolean(error('recipients'))} helperText={error('recipients') ?? 'Notifications go by email.'} className="w-full sm:w-72">
                            <MenuItem value="owners">Business owners</MenuItem>
                            <MenuItem value="assignee">The lead’s assignee (owners if unassigned)</MenuItem>
                        </TextField>
                    ) : null}
                    <MessageField label="Subject" multiline={false} value={config.subject} onChange={(subject) => set({ subject })} error={error('subject')} variables={variables} maxLength={limits.subject} />
                    <MessageField label="Message" value={config.message} onChange={(message) => set({ message })} error={error('message')} variables={variables} maxLength={limits.message} ai={ai} />
                </>
            ) : null}

            {step.action === 'ai_extract_lead' ? (
                <Alert severity="info" variant="outlined">
                    AI reads what the lead wrote (WhatsApp, Instagram, website enquiries) and fills in empty details: name, email, interest and budget. Details that are
                    already filled are never overwritten; differences wait on the lead for someone to accept.
                </Alert>
            ) : null}

            {step.action === 'ai_draft_reply' ? (
                <>
                    <Alert severity="info" variant="outlined">
                        AI prepares a reply in the inbox. Nothing is sent: someone on the team checks the draft and presses Send.
                    </Alert>
                    <TextField
                        size="small"
                        label="Instructions for AI (optional)"
                        fullWidth
                        multiline
                        minRows={2}
                        value={config.instructions ?? ''}
                        onChange={(event) => set({ instructions: event.target.value })}
                        error={Boolean(error('instructions'))}
                        helperText={error('instructions') ?? 'e.g. "Offer a free consultation and ask for a convenient time."'}
                        slotProps={{ htmlInput: { maxLength: 500 } }}
                    />
                </>
            ) : null}

            {step.action === 'ai_summarize' ? (
                <Alert severity="info" variant="outlined">
                    Adds a note to the timeline with an AI summary of the {trigger?.entities.includes('lead') ? 'lead' : 'customer'} and their recent history.
                </Alert>
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

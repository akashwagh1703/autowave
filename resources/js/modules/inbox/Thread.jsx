import { Link, router, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import BlockIcon from '@mui/icons-material/Block';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import SendIcon from '@mui/icons-material/Send';
import TextSnippetIcon from '@mui/icons-material/TextSnippetOutlined';
import UnarchiveIcon from '@mui/icons-material/UnarchiveOutlined';
import { useEffect, useRef, useState } from 'react';
import useTenant from '@/hooks/useTenant';
import MessageStatus from '@/modules/inbox/MessageStatus';
import TemplateDialog from '@/modules/inbox/TemplateDialog';
import { channelColors, channelIcons, uuid } from '@/modules/inbox/channels';
import { formatDateTime, formatRelative } from '@/utils/format';

function dayLabel(iso, timezone) {
    return formatDateTime(iso, timezone, { weekday: 'long', day: 'numeric', month: 'long' });
}

function Bubble({ message, timezone }) {
    const outbound = message.direction === 'outbound';

    return (
        <div className={`flex ${outbound ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-[80%] rounded-2xl px-3 py-2 text-sm shadow-sm ${
                    outbound ? 'rounded-br-sm bg-brand-600 text-white' : 'rounded-bl-sm border border-slate-200 bg-white text-slate-900'
                } ${message.status === 'failed' ? 'ring-2 ring-red-300' : ''}`}
            >
                {message.template ? <p className={`mb-1 text-[11px] font-medium ${outbound ? 'text-brand-100' : 'text-slate-500'}`}>Template · {message.template}</p> : null}
                <p className="break-words whitespace-pre-line">{message.body || <span className="italic opacity-75">(no text)</span>}</p>
                <div className={`mt-1 flex items-center justify-end gap-1 text-[11px] ${outbound ? 'text-brand-100' : 'text-slate-500'}`}>
                    {outbound && message.sender ? <span>{message.sender} ·</span> : null}
                    {message.simulated ? <span title="No channel connected: recorded, not delivered">simulated ·</span> : null}
                    <span title={formatDateTime(message.sent_at, timezone)}>{formatDateTime(message.sent_at, timezone, { hour: 'numeric', minute: '2-digit' })}</span>
                    {outbound ? <MessageStatus message={message} timezone={timezone} /> : null}
                </div>
            </div>
        </div>
    );
}

function Composer({ conversation, onTemplate }) {
    const form = useForm({ body: '', client_id: uuid() });
    const blocked = conversation.text_blocked;

    const submit = (event) => {
        event?.preventDefault();

        if (!form.data.body.trim() || form.processing) {
            return;
        }

        form.post(`/inbox/${conversation.id}/messages`, {
            preserveScroll: true,
            onSuccess: () => form.setData({ body: '', client_id: uuid() }),
        });
    };

    return (
        <form onSubmit={submit} noValidate className="border-t border-slate-200 bg-white p-3">
            {blocked ? (
                <Alert
                    severity="info"
                    variant="outlined"
                    className="mb-2"
                    action={
                        conversation.templates.length && !conversation.template_blocked ? (
                            <Button size="small" onClick={onTemplate}>
                                Send template
                            </Button>
                        ) : null
                    }
                >
                    {blocked}
                    {conversation.channel === 'whatsapp' && !conversation.templates.length ? ' Sync approved templates in Settings → Messaging.' : ''}
                </Alert>
            ) : null}
            <div className="flex items-end gap-2">
                <TextField
                    fullWidth
                    multiline
                    maxRows={6}
                    size="small"
                    placeholder={blocked ? 'Free-form replies are closed for this contact' : 'Type a reply… (Enter to send, Shift+Enter for a new line)'}
                    value={form.data.body}
                    disabled={Boolean(blocked)}
                    onChange={(event) => form.setData('body', event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
                            event.preventDefault();
                            submit();
                        }
                    }}
                    error={Boolean(form.errors.body)}
                    helperText={form.errors.body}
                    slotProps={{ htmlInput: { maxLength: 4096, 'aria-label': 'Reply' } }}
                />
                {conversation.templates.length ? (
                    <Tooltip title={conversation.template_blocked ?? 'Send an approved template'}>
                        <span>
                            <IconButton onClick={onTemplate} disabled={Boolean(conversation.template_blocked)} aria-label="Send a template">
                                <TextSnippetIcon />
                            </IconButton>
                        </span>
                    </Tooltip>
                ) : null}
                <Button type="submit" variant="contained" endIcon={<SendIcon />} disabled={Boolean(blocked) || form.processing || !form.data.body.trim()}>
                    Send
                </Button>
            </div>
        </form>
    );
}

export default function Thread({ conversation, members, backHref }) {
    const { timezone, can } = useTenant();
    const scroller = useRef(null);
    const [menu, setMenu] = useState(null);
    const [templateOpen, setTemplateOpen] = useState(false);
    const Icon = channelIcons[conversation.channel];
    const lastId = conversation.messages.at(-1)?.id;

    useEffect(() => {
        if (scroller.current) {
            scroller.current.scrollTop = scroller.current.scrollHeight;
        }
    }, [conversation.id, lastId]);

    const patch = (path, data) => router.patch(`/inbox/${conversation.id}/${path}`, data, { preserveScroll: true, preserveState: true });

    let previousDay = null;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-3 py-2">
                <IconButton component={Link} href={backHref} size="small" className="lg:!hidden" aria-label="Back to conversations">
                    <ArrowBackIcon fontSize="small" />
                </IconButton>
                <Icon className={channelColors[conversation.channel]} fontSize="small" />
                <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold text-slate-900">{conversation.name}</p>
                    <p className="truncate text-xs text-slate-500">
                        {conversation.handle ?? conversation.channel_label}
                        {conversation.profile_name && conversation.profile_name !== conversation.name ? ` · “${conversation.profile_name}”` : ''}
                        {conversation.customer ? (
                            <>
                                {' · '}
                                <Link href={`/customers/${conversation.customer.id}`} className="text-brand-700 hover:underline">
                                    Customer
                                </Link>
                            </>
                        ) : null}
                        {conversation.lead ? (
                            <>
                                {' · '}
                                <Link href={`/leads/${conversation.lead.id}`} className="text-brand-700 hover:underline">
                                    Lead
                                </Link>
                            </>
                        ) : null}
                    </p>
                </div>
                {conversation.opted_out ? <Chip size="small" color="warning" label="Opted out" /> : null}
                {conversation.status === 'closed' ? <Chip size="small" label="Closed" /> : null}
                {can('conversations.assign') ? (
                    <TextField
                        select
                        size="small"
                        label="Assigned to"
                        value={conversation.assignee?.id ?? ''}
                        onChange={(event) => patch('assign', { tenant_user_id: event.target.value || null })}
                        className="w-44"
                    >
                        <MenuItem value="">Unassigned</MenuItem>
                        {members.map((member) => (
                            <MenuItem key={member.id} value={member.id}>
                                {member.name}
                            </MenuItem>
                        ))}
                    </TextField>
                ) : conversation.assignee ? (
                    <Chip size="small" variant="outlined" label={conversation.assignee.name} />
                ) : null}
                {can('conversations.reply') ? (
                    <>
                        <Button
                            size="small"
                            variant="outlined"
                            startIcon={conversation.status === 'open' ? <CheckCircleOutlineIcon /> : <UnarchiveIcon />}
                            onClick={() => patch('status', { status: conversation.status === 'open' ? 'closed' : 'open' })}
                        >
                            {conversation.status === 'open' ? 'Close' : 'Reopen'}
                        </Button>
                        <IconButton size="small" onClick={(event) => setMenu(event.currentTarget)} aria-label="More actions">
                            <MoreVertIcon fontSize="small" />
                        </IconButton>
                        <Menu anchorEl={menu} open={menu !== null} onClose={() => setMenu(null)}>
                            <MenuItem
                                onClick={() => {
                                    setMenu(null);
                                    patch('opt-out', { opted_out: !conversation.opted_out });
                                }}
                            >
                                <ListItemIcon>
                                    <BlockIcon fontSize="small" />
                                </ListItemIcon>
                                {conversation.opted_out ? 'Remove opt-out' : 'Mark as opted out'}
                            </MenuItem>
                        </Menu>
                    </>
                ) : null}
            </div>

            {conversation.simulated ? (
                <p className="border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-800">
                    {conversation.channel_label} is not connected: replies are recorded here but not delivered.
                </p>
            ) : conversation.window.enforced && conversation.window.closes_at ? (
                <p className="border-b border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-600">Free-form replies allowed until {formatDateTime(conversation.window.closes_at, timezone, { weekday: 'short', hour: 'numeric', minute: '2-digit' })} ({formatRelative(conversation.window.closes_at)}).</p>
            ) : null}

            <div ref={scroller} className="min-h-0 flex-1 space-y-2 overflow-y-auto bg-slate-50 px-3 py-4" aria-live="polite">
                {conversation.has_older ? <p className="text-center text-xs text-slate-500">Older messages are on the timeline.</p> : null}
                {conversation.messages.length === 0 ? <p className="py-10 text-center text-sm text-slate-500">No messages yet. Say hello!</p> : null}
                {conversation.messages.map((message) => {
                    const day = dayLabel(message.sent_at, timezone);
                    const showDay = day !== previousDay;
                    previousDay = day;

                    return (
                        <div key={message.id}>
                            {showDay ? <p className="my-3 text-center text-xs font-medium text-slate-500">{day}</p> : null}
                            <Bubble message={message} timezone={timezone} />
                        </div>
                    );
                })}
            </div>

            {can('conversations.reply') ? <Composer key={conversation.id} conversation={conversation} onTemplate={() => setTemplateOpen(true)} /> : null}

            {conversation.templates.length ? (
                <TemplateDialog key={`t-${conversation.id}`} open={templateOpen} onClose={() => setTemplateOpen(false)} conversation={conversation} templates={conversation.templates} />
            ) : null}
        </div>
    );
}

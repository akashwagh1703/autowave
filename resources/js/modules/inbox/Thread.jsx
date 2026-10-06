import { Link, router, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import IconButton from '@mui/material/IconButton';
import LinearProgress from '@mui/material/LinearProgress';
import ListItemIcon from '@mui/material/ListItemIcon';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import BlockIcon from '@mui/icons-material/Block';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutlineOutlined';
import CloseIcon from '@mui/icons-material/Close';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import SendIcon from '@mui/icons-material/Send';
import TextSnippetIcon from '@mui/icons-material/TextSnippetOutlined';
import UnarchiveIcon from '@mui/icons-material/UnarchiveOutlined';
import { useEffect, useRef, useState } from 'react';
import useAi from '@/hooks/useAi';
import useTenant from '@/hooks/useTenant';
import SummaryCard from '@/modules/ai/SummaryCard';
import MessageFile from '@/modules/inbox/MessageFile';
import MessageStatus from '@/modules/inbox/MessageStatus';
import { errorMessage, postJson } from '@/utils/http';
import TemplateDialog from '@/modules/inbox/TemplateDialog';
import { channelColors, channelIcons, uuid } from '@/modules/inbox/channels';
import { formatBytes, formatDateTime, formatRelative } from '@/utils/format';

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
                {!outbound && message.type === 'interactive' ? <p className="mb-1 text-[11px] font-medium text-slate-500">Tapped an option</p> : null}
                {message.attachment ? <MessageFile file={message.attachment} outbound={outbound} /> : null}
                {message.image ? <img src={message.image} alt="" loading="lazy" className="mb-1 max-h-48 w-full rounded-lg object-cover" /> : null}
                {message.body || !message.attachment ? (
                    <p className={`break-words whitespace-pre-line ${message.attachment ? 'mt-1' : ''}`}>{message.body || <span className="italic opacity-75">(no text)</span>}</p>
                ) : null}
                {message.footer ? <p className="mt-1 text-xs opacity-75">{message.footer}</p> : null}
                {message.options?.length ? (
                    <ul className={`mt-2 space-y-1 border-t pt-2 ${outbound ? 'border-white/20' : 'border-slate-200'}`} aria-label="Options sent">
                        {message.options.map((option, index) => (
                            <li key={`${index}-${option}`} className={`rounded-md px-2 py-1 text-center text-xs font-medium ${outbound ? 'bg-white/15' : 'bg-slate-100'}`}>
                                {option}
                            </li>
                        ))}
                    </ul>
                ) : null}
                {message.media_status === 'pending' ? <p className="mt-1 text-xs italic opacity-75">Saving the file…</p> : null}
                {message.media_status === 'skipped' ? <p className="mt-1 text-xs opacity-75">File not saved: {message.media_error}</p> : null}
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

function AiDraft({ conversation, onUse }) {
    const [hidden, setHidden] = useState(null);
    const draft = conversation.ai_draft;

    if (!draft || hidden === draft.id) {
        return null;
    }

    const dismiss = () => {
        setHidden(draft.id);
        postJson(`/ai/conversations/${conversation.id}/drafts/${draft.id}/dismiss`).catch(() => {});
    };

    return (
        <Alert
            severity="info"
            icon={<AutoAwesomeIcon fontSize="inherit" />}
            className="mb-2"
            action={
                <div className="flex gap-1">
                    <Button
                        size="small"
                        onClick={() => {
                            onUse(draft.text);
                            setHidden(draft.id);
                        }}
                    >
                        Use
                    </Button>
                    <Button size="small" color="inherit" onClick={dismiss}>
                        Dismiss
                    </Button>
                </div>
            }
        >
            <p className="text-xs font-medium">AI drafted a reply — check it, then send it yourself.</p>
            <p className="mt-1 line-clamp-3 text-sm whitespace-pre-line">{draft.text}</p>
        </Alert>
    );
}

function SuggestReply({ conversation, body, onSuggest, onError, disabled }) {
    const ai = useAi();
    const [loading, setLoading] = useState(false);

    if (!ai.enabled) {
        return null;
    }

    const improving = body.trim() !== '';
    const label = improving ? 'Improve my draft with AI' : 'Suggest a reply with AI';

    const suggest = async () => {
        setLoading(true);
        onError(null);

        try {
            const data = await postJson(`/ai/conversations/${conversation.id}/reply`, { draft: improving ? body : null });
            onSuggest(data.text ?? '');
        } catch (failure) {
            onError(errorMessage(failure, 'AI could not suggest a reply right now.'));
        } finally {
            setLoading(false);
        }
    };

    return (
        <Tooltip title={ai.available ? label : (ai.message ?? '')}>
            <span>
                <IconButton onClick={suggest} disabled={disabled || loading || !ai.available} aria-label={label} color="primary">
                    {loading ? <CircularProgress size={20} /> : <AutoAwesomeIcon />}
                </IconButton>
            </span>
        </Tooltip>
    );
}

function Composer({ conversation, onTemplate }) {
    const form = useForm({ body: '', client_id: uuid(), file: null });
    const blocked = conversation.text_blocked;
    const files = conversation.files;
    const picker = useRef(null);
    const [aiError, setAiError] = useState(null);
    const [tooLarge, setTooLarge] = useState(null);
    const fileError = tooLarge ?? form.errors.file;
    const empty = !form.data.body.trim() && !form.data.file;

    const choose = (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        const extension = (file.name.split('.').pop() ?? '').toLowerCase();
        const kind = files.kinds.find((candidate) => candidate.extensions.includes(extension === 'jpeg' ? 'jpg' : extension));
        const limit = kind?.max_bytes ?? files.max_bytes;

        form.clearErrors('file');
        setTooLarge(file.size > limit ? `This file is ${formatBytes(file.size)}. The limit is ${formatBytes(limit)}.` : null);
        form.setData('file', file);
    };

    const clearFile = () => {
        form.setData('file', null);
        form.clearErrors('file');
        setTooLarge(null);
    };

    const submit = (event) => {
        event?.preventDefault();

        if (empty || form.processing || tooLarge) {
            return;
        }

        form.post(`/inbox/${conversation.id}/messages`, {
            preserveScroll: true,
            preserveState: true,
            forceFormData: Boolean(form.data.file),
            onSuccess: () => form.setData({ body: '', client_id: uuid(), file: null }),
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
            {!blocked ? <AiDraft conversation={conversation} onUse={(text) => form.setData('body', text)} /> : null}
            {aiError ? (
                <Alert severity="error" className="mb-2" onClose={() => setAiError(null)}>
                    {aiError}
                </Alert>
            ) : null}
            {form.data.file ? (
                <div className="mb-2 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-sm">
                    <AttachFileIcon fontSize="small" className="shrink-0 text-slate-500" />
                    <span className="min-w-0 flex-1 truncate" title={form.data.file.name}>
                        {form.data.file.name}
                    </span>
                    <span className="shrink-0 text-xs text-slate-500">{formatBytes(form.data.file.size)}</span>
                    <IconButton size="small" onClick={clearFile} disabled={form.processing} aria-label="Remove the file">
                        <CloseIcon fontSize="small" />
                    </IconButton>
                </div>
            ) : null}
            {form.progress && form.data.file ? <LinearProgress variant="determinate" value={form.progress.percentage ?? 0} className="mb-2" /> : null}
            {fileError ? (
                <p className="mb-2 text-sm text-red-600" role="alert">
                    {fileError}
                </p>
            ) : null}
            <div className="flex items-end gap-2">
                {files && !blocked ? (
                    <>
                        <input ref={picker} type="file" accept={files.accept} className="hidden" onChange={choose} aria-label="Attach a file" />
                        <Tooltip title={`Attach a file. ${files.hint}`}>
                            <span>
                                <IconButton onClick={() => picker.current?.click()} disabled={form.processing} aria-label="Attach a file">
                                    <AttachFileIcon />
                                </IconButton>
                            </span>
                        </Tooltip>
                    </>
                ) : null}
                <TextField
                    fullWidth
                    multiline
                    maxRows={6}
                    size="small"
                    placeholder={
                        blocked
                            ? 'Free-form replies are closed for this contact'
                            : form.data.file
                              ? 'Add a caption (optional)'
                              : 'Type a reply… (Enter to send, Shift+Enter for a new line)'
                    }
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
                <SuggestReply
                    conversation={conversation}
                    body={form.data.body}
                    disabled={Boolean(blocked) || form.processing}
                    onSuggest={(text) => form.setData('body', text)}
                    onError={setAiError}
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
                <Button type="submit" variant="contained" endIcon={<SendIcon />} disabled={Boolean(blocked) || form.processing || empty || Boolean(tooLarge)}>
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
    const [summaryOpen, setSummaryOpen] = useState(false);
    const ai = useAi();
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
                {ai.enabled ? (
                    <Tooltip title="AI summary of this conversation">
                        <IconButton size="small" onClick={() => setSummaryOpen(true)} aria-label="AI summary of this conversation" color="primary">
                            <AutoAwesomeIcon fontSize="small" />
                        </IconButton>
                    </Tooltip>
                ) : null}
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

            {ai.enabled ? (
                <Dialog open={summaryOpen} onClose={() => setSummaryOpen(false)} fullWidth maxWidth="sm">
                    <DialogContent>
                        <SummaryCard key={conversation.id} url={`/ai/conversations/${conversation.id}/summary`} title={`Conversation with ${conversation.name}`} variant="plain" />
                    </DialogContent>
                    <DialogActions>
                        <Button onClick={() => setSummaryOpen(false)} color="inherit">
                            Close
                        </Button>
                    </DialogActions>
                </Dialog>
            ) : null}
        </div>
    );
}

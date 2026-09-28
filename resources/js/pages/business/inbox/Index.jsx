import { Link, usePoll } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import LinearProgress from '@mui/material/LinearProgress';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import BlockIcon from '@mui/icons-material/Block';
import ForumIcon from '@mui/icons-material/ForumOutlined';
import AppLayout from '@/layouts/AppLayout';
import EmptyState from '@/components/EmptyState';
import Pagination from '@/components/Pagination';
import SearchField from '@/components/SearchField';
import Thread from '@/modules/inbox/Thread';
import { channelColors, channelIcons } from '@/modules/inbox/channels';
import useFilters from '@/hooks/useFilters';
import useTenant from '@/hooks/useTenant';
import { formatDateTime } from '@/utils/format';

function queryString(filters) {
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== ''));
    const text = query.toString();

    return text ? `?${text}` : '';
}

function shortTime(iso, timezone) {
    if (!iso) {
        return '';
    }

    const sameDay = formatDateTime(iso, timezone, { dateStyle: 'short' }) === formatDateTime(new Date().toISOString(), timezone, { dateStyle: 'short' });

    return formatDateTime(iso, timezone, sameDay ? { hour: 'numeric', minute: '2-digit' } : { day: 'numeric', month: 'short' });
}

function ConversationRow({ conversation, active, href, timezone }) {
    const Icon = channelIcons[conversation.channel];
    const unread = conversation.unread_count > 0;

    return (
        <li>
            <Link
                href={href}
                preserveScroll
                className={`flex gap-3 border-b border-slate-100 px-3 py-3 hover:bg-slate-50 ${active ? 'bg-brand-50 hover:bg-brand-50' : ''}`}
                aria-current={active ? 'true' : undefined}
            >
                <span className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-700">
                    {conversation.name.charAt(0).toUpperCase()}
                    <span className="absolute -right-1 -bottom-1 rounded-full bg-white p-px">
                        <Icon sx={{ fontSize: 14 }} className={channelColors[conversation.channel]} />
                    </span>
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex items-baseline justify-between gap-2">
                        <span className={`truncate text-sm ${unread ? 'font-semibold text-slate-900' : 'font-medium text-slate-800'}`}>{conversation.name}</span>
                        <span className={`shrink-0 text-xs ${unread ? 'font-semibold text-brand-700' : 'text-slate-500'}`}>{shortTime(conversation.last_message_at, timezone)}</span>
                    </span>
                    <span className="mt-0.5 flex items-center justify-between gap-2">
                        <span className={`truncate text-xs ${unread ? 'text-slate-800' : 'text-slate-500'}`}>
                            {conversation.last_direction === 'outbound' ? 'You: ' : ''}
                            {conversation.preview ?? 'No messages yet'}
                        </span>
                        <span className="flex shrink-0 items-center gap-1">
                            {conversation.opted_out ? <BlockIcon sx={{ fontSize: 14 }} className="text-amber-600" titleAccess="Opted out" /> : null}
                            {unread ? <span className="rounded-full bg-brand-600 px-1.5 text-[11px] leading-4 font-semibold text-white">{conversation.unread_count}</span> : null}
                        </span>
                    </span>
                    {conversation.assignee ? <span className="mt-0.5 block truncate text-[11px] text-slate-500">Assigned to {conversation.assignee.name}</span> : null}
                </span>
            </Link>
        </li>
    );
}

export default function Index({ conversations, filters: initialFilters, counts, channels, conversation, members, pollSeconds }) {
    const { timezone, can } = useTenant();
    const { filters, apply, applyDebounced, loading } = useFilters(conversation ? `/inbox/${conversation.id}` : '/inbox', initialFilters);
    const suffix = queryString(filters);
    const noneConnected = !Object.values(channels).some((channel) => channel.connected);

    usePoll(pollSeconds * 1000, { only: ['conversations', 'counts', 'conversation', 'inbox'] }, { keepAlive: false });

    const tabs = [
        { value: 'open', label: `Open${counts.open ? ` (${counts.open})` : ''}` },
        { value: 'mine', label: `Mine${counts.mine ? ` (${counts.mine})` : ''}` },
        { value: 'unassigned', label: `Unassigned${counts.unassigned ? ` (${counts.unassigned})` : ''}` },
        { value: 'closed', label: 'Closed' },
        { value: 'all', label: 'All' },
    ];

    return (
        <AppLayout title={conversation ? `${conversation.name} · Inbox` : 'Inbox'}>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">Inbox</h1>
                    <p className="text-sm text-slate-600">
                        WhatsApp and Instagram messages in one place{counts.unread ? ` · ${counts.unread} unread` : ''}.
                    </p>
                </div>
                {can('settings.view') ? (
                    <Button component={Link} href="/settings/messaging" variant="outlined">
                        Messaging settings
                    </Button>
                ) : null}
            </div>

            {noneConnected ? (
                <Alert severity="info" variant="outlined" className="mb-4">
                    No channel is connected yet, so replies are recorded but not delivered.
                    {can('settings.update') ? (
                        <>
                            {' '}
                            <Link href="/settings/messaging" className="font-medium text-brand-700 hover:underline">
                                Connect WhatsApp or Instagram
                            </Link>
                            .
                        </>
                    ) : ' Ask the owner to connect WhatsApp or Instagram.'}
                </Alert>
            ) : null}

            <Card variant="outlined" className="grid overflow-hidden lg:h-[calc(100vh-15rem)] lg:min-h-[32rem] lg:grid-cols-[22rem_1fr]">
                <div className={`flex min-h-0 flex-col border-slate-200 lg:border-r ${conversation ? 'hidden lg:flex' : 'flex'}`}>
                    <Tabs value={filters.view} onChange={(_, view) => apply({ view })} variant="scrollable" scrollButtons="auto" className="border-b border-slate-200">
                        {tabs.map((tab) => (
                            <Tab key={tab.value} value={tab.value} label={tab.label} sx={{ minWidth: 0, px: 1.5 }} />
                        ))}
                    </Tabs>
                    <div className="flex gap-2 border-b border-slate-200 p-3">
                        <SearchField value={filters.search} onChange={(search) => applyDebounced({ search })} placeholder="Name or number" loading={loading} className="min-w-0 flex-1" />
                        <TextField select size="small" label="Channel" value={filters.channel ?? ''} onChange={(event) => apply({ channel: event.target.value || null })} className="w-32">
                            <MenuItem value="">All</MenuItem>
                            {Object.entries(channels).map(([key, channel]) => (
                                <MenuItem key={key} value={key}>
                                    {channel.label}
                                </MenuItem>
                            ))}
                        </TextField>
                    </div>
                    {loading ? <LinearProgress /> : <div className="h-1" />}
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {conversations.data.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={ForumIcon}
                                    title={filters.search || filters.channel ? 'No conversations match' : 'No conversations here'}
                                    description="When customers message your WhatsApp number or Instagram account, their chats appear here."
                                />
                            </div>
                        ) : (
                            <ul>
                                {conversations.data.map((item) => (
                                    <ConversationRow key={item.id} conversation={item} active={conversation?.id === item.id} href={`/inbox/${item.id}${suffix}`} timezone={timezone} />
                                ))}
                            </ul>
                        )}
                    </div>
                    {conversations.meta.last_page > 1 ? (
                        <div className="border-t border-slate-200 px-3">
                            <Pagination meta={conversations.meta} noun="conversations" />
                        </div>
                    ) : null}
                </div>

                <div className={`min-h-[70vh] lg:min-h-0 ${conversation ? 'block' : 'hidden lg:block'}`}>
                    {conversation ? (
                        <Thread conversation={conversation} members={members} backHref={`/inbox${suffix}`} />
                    ) : (
                        <div className="flex h-full items-center justify-center p-6">
                            <EmptyState icon={ForumIcon} title="Pick a conversation" description="Select a chat on the left to read and reply." />
                        </div>
                    )}
                </div>
            </Card>
        </AppLayout>
    );
}

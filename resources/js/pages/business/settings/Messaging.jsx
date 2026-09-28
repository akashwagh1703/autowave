import { Link, router, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableContainer from '@mui/material/TableContainer';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import SyncIcon from '@mui/icons-material/Sync';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';
import { channelColors, channelIcons } from '@/modules/inbox/channels';
import { formatDateTime, formatRelative } from '@/utils/format';

function CopyField({ label, value }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            setCopied(false);
        }
    };

    return (
        <TextField
            label={label}
            size="small"
            fullWidth
            value={value}
            slotProps={{
                htmlInput: { readOnly: true, className: 'font-mono text-xs' },
                input: {
                    endAdornment: (
                        <Tooltip title={copied ? 'Copied' : 'Copy'}>
                            <IconButton size="small" onClick={copy} aria-label={`Copy ${label.toLowerCase()}`}>
                                <ContentCopyIcon fontSize="small" />
                            </IconButton>
                        </Tooltip>
                    ),
                },
            }}
        />
    );
}

function SecretField({ form, name, label, saved, disabled }) {
    return (
        <TextField
            label={label}
            type="password"
            size="small"
            fullWidth
            autoComplete="off"
            value={form.data[name]}
            disabled={disabled}
            onChange={(event) => form.setData(name, event.target.value)}
            placeholder={saved ? 'Saved — leave blank to keep' : ''}
            error={Boolean(form.errors[name])}
            helperText={form.errors[name] ?? (saved ? 'A value is saved. It is never shown again.' : 'Stored encrypted. It is never shown again.')}
            slotProps={{ inputLabel: { shrink: saved || Boolean(form.data[name]) || undefined } }}
        />
    );
}

function ChannelHeader({ channel, title }) {
    const Icon = channelIcons[channel.channel];

    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="flex items-center gap-2 font-semibold text-slate-900">
                <Icon className={channelColors[channel.channel]} /> {title}
            </h2>
            <Chip size="small" color={channel.connected ? 'success' : 'default'} label={channel.connected ? 'Connected' : 'Not connected'} />
        </div>
    );
}

function ConnectionSummary({ channel, timezone }) {
    if (!channel.connected) {
        return null;
    }

    return (
        <dl className="grid gap-3 rounded-lg bg-slate-50 p-3 text-sm sm:grid-cols-2">
            <div>
                <dt className="text-xs text-slate-500">Account</dt>
                <dd className="font-medium text-slate-900">{[channel.display_name, channel.display_handle].filter(Boolean).join(' · ') || channel.external_id}</dd>
            </div>
            <div>
                <dt className="text-xs text-slate-500">Last webhook</dt>
                <dd className="text-slate-900">{channel.last_webhook_at ? formatRelative(channel.last_webhook_at) : 'None received yet'}</dd>
            </div>
            <div>
                <dt className="text-xs text-slate-500">Connected</dt>
                <dd className="text-slate-900">{formatDateTime(channel.connected_at, timezone)}</dd>
            </div>
        </dl>
    );
}

function WebhookSetup({ channel, field }) {
    if (!channel.callback_url) {
        return null;
    }

    return (
        <div className="space-y-3 rounded-lg border border-dashed border-slate-300 p-3">
            <p className="text-sm text-slate-700">
                In your Meta app, open <strong>Webhooks</strong>, paste this callback URL and verify token, then subscribe to the <code className="rounded bg-slate-100 px-1">{field}</code> field.
            </p>
            <CopyField label="Callback URL" value={channel.callback_url} />
            <CopyField label="Verify token" value={channel.verify_token} />
        </div>
    );
}

function WhatsAppCard({ channel, canUpdate, onDisconnect, timezone }) {
    const form = useForm({
        phone_number_id: channel.external_id ?? '',
        business_account_id: channel.business_account_id ?? '',
        access_token: '',
        app_secret: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/settings/messaging/whatsapp', { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, access_token: '', app_secret: '' }) });
    };

    return (
        <Card variant="outlined">
            <CardContent>
                <form onSubmit={submit} noValidate className="space-y-4">
                    <ChannelHeader channel={channel} title="WhatsApp Business" />
                    <p className="text-sm text-slate-600">
                        Use the WhatsApp Cloud API with your own Meta app. You will find these under <strong>WhatsApp → API setup</strong> in the Meta developer dashboard. Use a permanent (system user) token.
                    </p>
                    <ConnectionSummary channel={channel} timezone={timezone} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField
                            label="Phone number ID"
                            size="small"
                            value={form.data.phone_number_id}
                            disabled={!canUpdate}
                            onChange={(event) => form.setData('phone_number_id', event.target.value.trim())}
                            error={Boolean(form.errors.phone_number_id)}
                            helperText={form.errors.phone_number_id}
                            slotProps={{ htmlInput: { inputMode: 'numeric' } }}
                        />
                        <TextField
                            label="WhatsApp Business Account ID"
                            size="small"
                            value={form.data.business_account_id}
                            disabled={!canUpdate}
                            onChange={(event) => form.setData('business_account_id', event.target.value.trim())}
                            error={Boolean(form.errors.business_account_id)}
                            helperText={form.errors.business_account_id ?? 'Needed to sync message templates.'}
                            slotProps={{ htmlInput: { inputMode: 'numeric' } }}
                        />
                        <SecretField form={form} name="access_token" label="Access token" saved={channel.has_token} disabled={!canUpdate} />
                        <SecretField form={form} name="app_secret" label="App secret" saved={channel.has_secret} disabled={!canUpdate} />
                    </div>
                    <WebhookSetup channel={channel} field="messages" />
                    {canUpdate ? (
                        <div className="flex flex-wrap justify-end gap-2">
                            {channel.connected ? (
                                <Button color="error" onClick={() => onDisconnect('whatsapp')}>
                                    Disconnect
                                </Button>
                            ) : null}
                            <Button type="submit" variant="contained" disabled={form.processing}>
                                {channel.connected ? 'Save and re-check' : 'Connect WhatsApp'}
                            </Button>
                        </div>
                    ) : null}
                </form>
            </CardContent>
        </Card>
    );
}

function InstagramCard({ channel, canUpdate, onDisconnect, timezone }) {
    const form = useForm({ account_id: channel.external_id ?? '', access_token: '', app_secret: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/settings/messaging/instagram', { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, access_token: '', app_secret: '' }) });
    };

    return (
        <Card variant="outlined">
            <CardContent>
                <form onSubmit={submit} noValidate className="space-y-4">
                    <ChannelHeader channel={channel} title="Instagram direct messages" />
                    <p className="text-sm text-slate-600">
                        Uses the Instagram API with Instagram Login for a professional account. Instagram only allows replies within 24 hours of the customer’s last message.
                    </p>
                    <ConnectionSummary channel={channel} timezone={timezone} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField
                            label="Instagram account ID (optional)"
                            size="small"
                            value={form.data.account_id}
                            disabled={!canUpdate}
                            onChange={(event) => form.setData('account_id', event.target.value.trim())}
                            error={Boolean(form.errors.account_id)}
                            helperText={form.errors.account_id ?? 'Read from the token when left blank.'}
                            slotProps={{ htmlInput: { inputMode: 'numeric' } }}
                        />
                        <div className="hidden sm:block" />
                        <SecretField form={form} name="access_token" label="Access token" saved={channel.has_token} disabled={!canUpdate} />
                        <SecretField form={form} name="app_secret" label="App secret" saved={channel.has_secret} disabled={!canUpdate} />
                    </div>
                    <WebhookSetup channel={channel} field="messages" />
                    {canUpdate ? (
                        <div className="flex flex-wrap justify-end gap-2">
                            {channel.connected ? (
                                <Button color="error" onClick={() => onDisconnect('instagram')}>
                                    Disconnect
                                </Button>
                            ) : null}
                            <Button type="submit" variant="contained" disabled={form.processing}>
                                {channel.connected ? 'Save and re-check' : 'Connect Instagram'}
                            </Button>
                        </div>
                    ) : null}
                </form>
            </CardContent>
        </Card>
    );
}

function TemplatesCard({ templates, canSync, whatsappConnected }) {
    const [syncing, setSyncing] = useState(false);
    const [error, setError] = useState(null);
    const lastSync = templates.reduce((latest, template) => (template.synced_at > (latest ?? '') ? template.synced_at : latest), null);

    const sync = () => {
        router.post('/settings/messaging/templates/sync', {}, {
            preserveScroll: true,
            onStart: () => {
                setSyncing(true);
                setError(null);
            },
            onError: (errors) => setError(errors.templates ?? 'Sync failed.'),
            onFinish: () => setSyncing(false),
        });
    };

    return (
        <Card variant="outlined">
            <CardContent className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="font-semibold text-slate-900">WhatsApp templates</h2>
                        <p className="text-sm text-slate-600">
                            Create templates in WhatsApp Manager; once Meta approves them, sync here. Automations and messages after the 24-hour window use approved templates.
                            {lastSync ? ` Last synced ${formatRelative(lastSync)}.` : ''}
                        </p>
                    </div>
                    {canSync ? (
                        <Button variant="outlined" startIcon={<SyncIcon />} onClick={sync} disabled={syncing || !whatsappConnected}>
                            {syncing ? 'Syncing…' : 'Sync templates'}
                        </Button>
                    ) : null}
                </div>
                {error ? <Alert severity="error">{error}</Alert> : null}
                {templates.length === 0 ? (
                    <p className="text-sm text-slate-500">{whatsappConnected ? 'No templates synced yet.' : 'Connect WhatsApp to sync templates.'}</p>
                ) : (
                    <TableContainer>
                        <Table size="small">
                            <TableHead>
                                <TableRow>
                                    <TableCell>Template</TableCell>
                                    <TableCell>Status</TableCell>
                                    <TableCell className="hidden md:table-cell">Text</TableCell>
                                </TableRow>
                            </TableHead>
                            <TableBody>
                                {templates.map((template) => (
                                    <TableRow key={template.id}>
                                        <TableCell>
                                            <span className="font-medium text-slate-900">{template.name}</span>
                                            <p className="text-xs text-slate-500">
                                                {template.language}
                                                {template.category ? ` · ${template.category.toLowerCase()}` : ''}
                                                {template.variables ? ` · ${template.variables} variable${template.variables === 1 ? '' : 's'}` : ''}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            <Chip size="small" color={template.approved ? 'success' : template.status === 'REJECTED' ? 'error' : 'default'} label={template.status.toLowerCase()} />
                                        </TableCell>
                                        <TableCell className="hidden max-w-md md:table-cell">
                                            <p className="line-clamp-2 text-sm text-slate-700" title={template.body ?? ''}>
                                                {template.body ?? '—'}
                                            </p>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </TableContainer>
                )}
            </CardContent>
        </Card>
    );
}

function PreferencesCard({ quietHours, email, canUpdate }) {
    const { timezone } = useTenant();
    const form = useForm({ quiet_hours: quietHours, email: { from_name: email.from_name ?? '', reply_to: email.reply_to ?? '' } });
    const setQuiet = (changes) => form.setData('quiet_hours', { ...form.data.quiet_hours, ...changes });
    const setEmail = (changes) => form.setData('email', { ...form.data.email, ...changes });

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/messaging', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} noValidate>
            <Card variant="outlined">
                <CardContent className="space-y-5">
                    <div className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Quiet hours</h2>
                        <FormControlLabel
                            control={<Switch checked={form.data.quiet_hours.enabled} disabled={!canUpdate} onChange={(event) => setQuiet({ enabled: event.target.checked })} />}
                            label="Hold automation messages during quiet hours"
                        />
                        <p className="text-sm text-slate-600">
                            Automated WhatsApp, Instagram and email messages that would go out in this window wait until it ends ({timezone}). Replies you type in the inbox and team notifications are
                            sent straight away.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <TextField
                                type="time"
                                size="small"
                                label="From"
                                value={form.data.quiet_hours.start}
                                disabled={!canUpdate || !form.data.quiet_hours.enabled}
                                onChange={(event) => setQuiet({ start: event.target.value })}
                                error={Boolean(form.errors['quiet_hours.start'])}
                                helperText={form.errors['quiet_hours.start']}
                                slotProps={{ inputLabel: { shrink: true } }}
                            />
                            <TextField
                                type="time"
                                size="small"
                                label="Until"
                                value={form.data.quiet_hours.end}
                                disabled={!canUpdate || !form.data.quiet_hours.enabled}
                                onChange={(event) => setQuiet({ end: event.target.value })}
                                error={Boolean(form.errors['quiet_hours.end'])}
                                helperText={form.errors['quiet_hours.end']}
                                slotProps={{ inputLabel: { shrink: true } }}
                            />
                        </div>
                    </div>

                    <div className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Email sender</h2>
                        <p className="text-sm text-slate-600">Automation emails are sent from AutoWave’s address with your business name. Replies go to the reply-to address.</p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <TextField
                                label="Sender name"
                                size="small"
                                value={form.data.email.from_name}
                                disabled={!canUpdate}
                                onChange={(event) => setEmail({ from_name: event.target.value })}
                                error={Boolean(form.errors['email.from_name'])}
                                helperText={form.errors['email.from_name'] ?? 'Defaults to your business name.'}
                                slotProps={{ htmlInput: { maxLength: 100 } }}
                            />
                            <TextField
                                label="Reply-to email"
                                type="email"
                                size="small"
                                value={form.data.email.reply_to}
                                disabled={!canUpdate}
                                onChange={(event) => setEmail({ reply_to: event.target.value })}
                                error={Boolean(form.errors['email.reply_to'])}
                                helperText={form.errors['email.reply_to'] ?? 'Optional.'}
                            />
                        </div>
                    </div>

                    {canUpdate ? (
                        <div className="flex justify-end">
                            <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                                Save preferences
                            </Button>
                        </div>
                    ) : null}
                </CardContent>
            </Card>
        </form>
    );
}

export default function Messaging({ channels, templates, quietHours, email }) {
    const { can, timezone } = useTenant();
    const canUpdate = can('settings.update');
    const [disconnecting, setDisconnecting] = useState(null);
    const [processing, setProcessing] = useState(false);

    const disconnect = () => {
        router.post('/settings/messaging/disconnect', { channel: disconnecting }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDisconnecting(null);
            },
        });
    };

    return (
        <AppLayout title="Messaging settings">
            <Button component={Link} href="/settings" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Settings
            </Button>
            <PageHeader
                title="Messaging"
                description={canUpdate ? 'Connect WhatsApp and Instagram, manage templates, quiet hours and the email sender.' : 'You have view-only access.'}
                actions={
                    can('conversations.view') ? (
                        <Button component={Link} href="/inbox" variant="outlined">
                            Open inbox
                        </Button>
                    ) : null
                }
            />

            <div className="max-w-4xl space-y-4">
                <WhatsAppCard channel={channels.whatsapp} canUpdate={canUpdate} onDisconnect={setDisconnecting} timezone={timezone} />
                <TemplatesCard templates={templates} canSync={canUpdate} whatsappConnected={channels.whatsapp.connected} />
                <InstagramCard channel={channels.instagram} canUpdate={canUpdate} onDisconnect={setDisconnecting} timezone={timezone} />
                <PreferencesCard quietHours={quietHours} email={email} canUpdate={canUpdate} />
            </div>

            <ConfirmDialog
                open={disconnecting !== null}
                title={`Disconnect ${disconnecting === 'instagram' ? 'Instagram' : 'WhatsApp'}?`}
                description="The saved token and app secret are deleted. New messages are recorded but not delivered until you connect again. The webhook URL stays the same."
                confirmLabel="Disconnect"
                destructive
                processing={processing}
                onConfirm={disconnect}
                onClose={() => setDisconnecting(null)}
            />
        </AppLayout>
    );
}

import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ListIcon from '@mui/icons-material/FormatListBulleted';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';

/** Same choice as the assistant: the main action, then what the business offers, then "More options". */
function topButtons(offered) {
    if (offered.length <= 3) {
        return offered.map((item) => item.button);
    }

    const pick = (keys) => offered.find((item) => keys.includes(item.item));
    const first = [pick(['book', 'reserve', 'order']), pick(['services', 'courses', 'rates']), ...offered.filter((item) => item.item !== 'human')].filter(Boolean);

    return [...[...new Set(first)].slice(0, 2).map((item) => item.button), 'More options'];
}

function Preview({ welcome, business, image, buttons }) {
    const body = welcome.replaceAll('{name}', 'Priya').replaceAll('{business}', business);

    return (
        <div className="rounded-3xl border border-slate-200 bg-[#efeae2] p-4 shadow-inner">
            <p className="mb-3 text-center text-[11px] font-medium tracking-wide text-slate-500 uppercase">Preview</p>
            <div className="flex justify-end">
                <p className="rounded-xl rounded-tr-sm bg-[#d9fdd3] px-3 py-1.5 text-sm text-slate-900 shadow-sm">Hi</p>
            </div>
            <div className="mt-2 max-w-[90%] overflow-hidden rounded-xl rounded-tl-sm bg-white shadow-sm">
                {image ? <img src={image} alt="" className="h-32 w-full object-cover" /> : null}
                <p className="px-3 py-2 text-sm whitespace-pre-line text-slate-900">{body}</p>
                <div className="divide-y divide-slate-100 border-t border-slate-100">
                    {buttons.map((label) => (
                        <p key={label} className="flex items-center justify-center gap-1 py-2 text-sm font-medium text-sky-600">
                            {label === 'More options' ? <ListIcon sx={{ fontSize: 16 }} /> : null}
                            {label}
                        </p>
                    ))}
                </div>
            </div>
        </div>
    );
}

export default function WhatsAppAssistant({ settings, items, business, defaultWelcome, image, connected, ownerAlerts, overlapping, limits }) {
    const { can } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({
        enabled: settings.enabled,
        welcome: settings.welcome ?? '',
        show_image: settings.show_image,
        items: settings.items,
        pause_hours: settings.pause_hours,
        alert_team: settings.alert_team,
    });
    const locked = !canUpdate;
    const offered = items.filter((item) => item.available && form.data.items.includes(item.item));

    const toggleItem = (item, checked) => {
        form.setData('items', checked ? [...form.data.items, item] : form.data.items.filter((value) => value !== item));
    };

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/whatsapp-assistant', { preserveScroll: true });
    };

    return (
        <AppLayout title="WhatsApp assistant">
            <Button component={Link} href="/settings" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Settings
            </Button>
            <PageHeader
                title="WhatsApp assistant"
                description={
                    canUpdate
                        ? 'Answers customers on WhatsApp straight away with a menu of what your business offers. It only replies when a customer messages you; it never starts a chat.'
                        : 'You have view-only access.'
                }
            />

            {!connected ? (
                <Alert
                    severity="info"
                    className="mb-4 max-w-5xl"
                    action={
                        <Button component={Link} href="/settings/messaging" size="small">
                            Connect WhatsApp
                        </Button>
                    }
                >
                    WhatsApp is not connected yet. Until it is, the assistant’s replies are recorded in the inbox but not delivered.
                </Alert>
            ) : null}

            {form.data.enabled && overlapping.length ? (
                <Alert severity="warning" className="mb-4 max-w-5xl">
                    These automations also send a WhatsApp message when someone writes to you, so customers would get two replies. Pause them if the assistant’s
                    welcome is enough:{' '}
                    {overlapping.map((automation, index) => (
                        <span key={automation.id}>
                            {index > 0 ? ', ' : ''}
                            <Link href={`/automations/${automation.id}`} className="font-medium underline">
                                {automation.name}
                            </Link>
                        </span>
                    ))}
                    .
                </Alert>
            ) : null}

            <form onSubmit={submit} noValidate className="grid max-w-5xl gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
                <div className="min-w-0 space-y-4">
                    <Card variant="outlined">
                        <CardContent className="space-y-2">
                            <FormControlLabel
                                control={<Switch checked={form.data.enabled} disabled={locked} onChange={(event) => form.setData('enabled', event.target.checked)} />}
                                label={<span className="font-semibold text-slate-900">Reply to WhatsApp messages automatically</span>}
                            />
                            <p className="text-sm text-slate-600">
                                Customers tap buttons to see your services and prices, offers, timings and location, or to book and order online. Questions and
                                “Talk to a person” go to your team in the inbox.
                            </p>
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent className="space-y-3">
                            <h2 className="font-semibold text-slate-900">Welcome message</h2>
                            <TextField
                                fullWidth
                                multiline
                                minRows={3}
                                label="Welcome message"
                                placeholder={defaultWelcome}
                                value={form.data.welcome}
                                disabled={locked}
                                onChange={(event) => form.setData('welcome', event.target.value)}
                                error={Boolean(form.errors.welcome)}
                                helperText={form.errors.welcome ?? 'Use {name} for the customer’s first name and {business} for your business name. Leave empty to use the example.'}
                                slotProps={{ htmlInput: { maxLength: limits.welcome } }}
                            />
                            <FormControlLabel
                                control={<Switch checked={form.data.show_image} disabled={locked} onChange={(event) => form.setData('show_image', event.target.checked)} />}
                                label="Show your website’s main photo above the welcome"
                            />
                            {form.data.show_image && !image ? (
                                <p className="text-sm text-slate-500">Upload a hero photo or logo (JPEG or PNG) on your website to show one here.</p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent className="space-y-3">
                            <div>
                                <h2 className="font-semibold text-slate-900">Menu</h2>
                                <p className="text-sm text-slate-600">Options the assistant offers. Each uses your live services, prices, offers and website, so it is always up to date.</p>
                            </div>
                            <ul className="divide-y divide-slate-100">
                                {items.map((item) => (
                                    <li key={item.item} className="flex items-start gap-2 py-2">
                                        <Checkbox
                                            checked={item.item === 'human' || form.data.items.includes(item.item)}
                                            disabled={locked || item.item === 'human' || !item.available}
                                            onChange={(event) => toggleItem(item.item, event.target.checked)}
                                            slotProps={{ input: { 'aria-label': item.title } }}
                                            size="small"
                                            className="!-mt-1"
                                        />
                                        <div className="min-w-0">
                                            <p className={`text-sm font-medium ${item.available ? 'text-slate-900' : 'text-slate-400'}`}>{item.title}</p>
                                            <p className="text-xs text-slate-500">{item.item === 'human' ? 'Always offered, so customers can always reach you.' : (item.hint ?? item.description)}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {form.errors.items ? <p className="text-sm text-red-600">{form.errors.items}</p> : null}
                        </CardContent>
                    </Card>

                    <Card variant="outlined">
                        <CardContent className="space-y-3">
                            <h2 className="font-semibold text-slate-900">When your team takes over</h2>
                            <p className="text-sm text-slate-600">
                                The assistant goes quiet in a chat when someone from your team replies there, or when the customer asks for a person. It starts again after:
                            </p>
                            <TextField
                                type="number"
                                label="Quiet for"
                                className="w-40"
                                value={form.data.pause_hours}
                                disabled={locked}
                                onChange={(event) => form.setData('pause_hours', event.target.value)}
                                error={Boolean(form.errors.pause_hours)}
                                helperText={form.errors.pause_hours}
                                slotProps={{
                                    htmlInput: { min: limits.min, max: limits.max },
                                    input: { endAdornment: <InputAdornment position="end">hours</InputAdornment> },
                                }}
                            />
                            <FormControlLabel
                                control={<Switch checked={form.data.alert_team} disabled={locked} onChange={(event) => form.setData('alert_team', event.target.checked)} />}
                                label="Email the owners when a customer asks for a person or sends a question"
                            />
                            {form.data.alert_team && !ownerAlerts ? (
                                <p className="text-sm text-amber-700">
                                    Email alerts are off in{' '}
                                    <Link href="/settings/messaging" className="font-medium underline">
                                        Settings → Messaging
                                    </Link>
                                    , so no email is sent.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    {canUpdate ? (
                        <div className="flex justify-end">
                            <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                                Save settings
                            </Button>
                        </div>
                    ) : null}
                </div>

                <div className="lg:sticky lg:top-4 lg:self-start">
                    <Preview welcome={form.data.welcome.trim() || defaultWelcome} business={business} image={form.data.show_image ? image : null} buttons={topButtons(offered)} />
                    {offered.length > 3 ? <p className="mt-2 text-xs text-slate-500">“More options” opens the full menu as a list.</p> : null}
                </div>
            </form>
        </AppLayout>
    );
}

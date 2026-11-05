import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControl from '@mui/material/FormControl';
import FormControlLabel from '@mui/material/FormControlLabel';
import FormHelperText from '@mui/material/FormHelperText';
import InputLabel from '@mui/material/InputLabel';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';

export default function Loyalty({ loyalty, join_url, join_qr, products, has_booking, has_commerce }) {
    const { can } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({
        enabled: loyalty.enabled,
        stamps_required: loyalty.stamps_required,
        earn_per: loyalty.earn_per,
        product_ids: loyalty.product_ids ?? [],
        earn_appointments: loyalty.earn_appointments,
        reward_label: loyalty.reward_label ?? '',
        join_heading: loyalty.join_heading ?? '',
        join_blurb: loyalty.join_blurb ?? '',
        collect_birthday: loyalty.collect_birthday,
        birthday_mode: loyalty.birthday_mode,
        birthday_grant_reward: loyalty.birthday_grant_reward,
        birthday_reward_label: loyalty.birthday_reward_label ?? '',
    });

    const toggleProduct = (id) => {
        const next = form.data.product_ids.includes(id)
            ? form.data.product_ids.filter((value) => value !== id)
            : [...form.data.product_ids, id];
        form.setData('product_ids', next);
    };

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/loyalty', { preserveScroll: true });
    };

    return (
        <AppLayout title="Loyalty">
            <Button component={Link} href="/settings" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Settings
            </Button>
            <PageHeader
                title="Loyalty stamp card"
                description={canUpdate ? 'Customers collect stamps and unlock a free reward. Share the QR so walk-ins can join.' : 'You have view-only access.'}
            />

            <form onSubmit={submit} noValidate className="max-w-3xl space-y-4">
                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Stamp card</h2>
                        <FormControlLabel
                            control={<Switch checked={form.data.enabled} disabled={!canUpdate} onChange={(event) => form.setData('enabled', event.target.checked)} />}
                            label="Loyalty rewards are on"
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <TextField
                                label="Stamps for one reward"
                                type="number"
                                fullWidth
                                value={form.data.stamps_required}
                                disabled={!canUpdate || !form.data.enabled}
                                onChange={(event) => form.setData('stamps_required', event.target.value)}
                                error={Boolean(form.errors.stamps_required)}
                                helperText={form.errors.stamps_required ?? 'Example: 10 = buy 9, get the 10th free.'}
                                slotProps={{ htmlInput: { min: 2, max: 50 } }}
                            />
                            <TextField
                                label="Reward name"
                                fullWidth
                                value={form.data.reward_label}
                                disabled={!canUpdate || !form.data.enabled}
                                onChange={(event) => form.setData('reward_label', event.target.value)}
                                error={Boolean(form.errors.reward_label)}
                                helperText={form.errors.reward_label ?? 'e.g. Free coffee'}
                            />
                        </div>
                        {has_commerce ? (
                            <>
                                <FormControl fullWidth disabled={!canUpdate || !form.data.enabled} error={Boolean(form.errors.earn_per)}>
                                    <InputLabel id="earn-per-label">Earn stamps</InputLabel>
                                    <Select
                                        labelId="earn-per-label"
                                        label="Earn stamps"
                                        value={form.data.earn_per}
                                        onChange={(event) => form.setData('earn_per', event.target.value)}
                                    >
                                        <MenuItem value="item">One stamp per product unit on a completed order</MenuItem>
                                        <MenuItem value="order">One stamp per completed order</MenuItem>
                                    </Select>
                                    {form.errors.earn_per ? <FormHelperText>{form.errors.earn_per}</FormHelperText> : null}
                                </FormControl>
                                <div className="space-y-1">
                                    <p className="text-sm font-medium text-slate-800">Which products count?</p>
                                    <p className="text-sm text-slate-600">Leave all unchecked to count every product.</p>
                                    <div className="max-h-48 space-y-1 overflow-y-auto rounded border border-slate-200 p-2">
                                        {products.length === 0 ? (
                                            <p className="text-sm text-slate-500">Add products first.</p>
                                        ) : (
                                            products.map((product) => (
                                                <FormControlLabel
                                                    key={product.id}
                                                    control={
                                                        <Checkbox
                                                            checked={form.data.product_ids.includes(product.id)}
                                                            disabled={!canUpdate || !form.data.enabled}
                                                            onChange={() => toggleProduct(product.id)}
                                                        />
                                                    }
                                                    label={`${product.name}${product.active ? '' : ' (inactive)'}`}
                                                />
                                            ))
                                        )}
                                    </div>
                                </div>
                            </>
                        ) : null}
                        {has_booking ? (
                            <FormControlLabel
                                control={
                                    <Switch
                                        checked={form.data.earn_appointments}
                                        disabled={!canUpdate || !form.data.enabled}
                                        onChange={(event) => form.setData('earn_appointments', event.target.checked)}
                                    />
                                }
                                label="Also give one stamp when an appointment is completed"
                            />
                        ) : null}
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Birthdays</h2>
                        <FormControlLabel
                            control={
                                <Switch
                                    checked={form.data.collect_birthday}
                                    disabled={!canUpdate || !form.data.enabled}
                                    onChange={(event) => form.setData('collect_birthday', event.target.checked)}
                                />
                            }
                            label="Ask for birthday on the join QR form"
                        />
                        <FormControl fullWidth disabled={!canUpdate || !form.data.enabled} error={Boolean(form.errors.birthday_mode)}>
                            <InputLabel id="birthday-mode-label">Greet on</InputLabel>
                            <Select
                                labelId="birthday-mode-label"
                                label="Greet on"
                                value={form.data.birthday_mode}
                                onChange={(event) => form.setData('birthday_mode', event.target.value)}
                            >
                                <MenuItem value="day">The birthday itself</MenuItem>
                                <MenuItem value="month">The 1st of their birth month</MenuItem>
                            </Select>
                            {form.errors.birthday_mode ? <FormHelperText>{form.errors.birthday_mode}</FormHelperText> : (
                                <FormHelperText>Turn on the paused “Wish customers a happy birthday” automation to send WhatsApp.</FormHelperText>
                            )}
                        </FormControl>
                        <FormControlLabel
                            control={
                                <Switch
                                    checked={form.data.birthday_grant_reward}
                                    disabled={!canUpdate || !form.data.enabled}
                                    onChange={(event) => form.setData('birthday_grant_reward', event.target.checked)}
                                />
                            }
                            label="Give a free stamp-card reward on their birthday"
                        />
                        <TextField
                            label="Birthday reward name"
                            fullWidth
                            value={form.data.birthday_reward_label}
                            disabled={!canUpdate || !form.data.enabled || !form.data.birthday_grant_reward}
                            onChange={(event) => form.setData('birthday_reward_label', event.target.value)}
                            error={Boolean(form.errors.birthday_reward_label)}
                            helperText={form.errors.birthday_reward_label ?? 'Tops up stamps so staff can redeem one free item.'}
                        />
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Join page (QR)</h2>
                        <TextField
                            label="Heading"
                            fullWidth
                            value={form.data.join_heading}
                            disabled={!canUpdate || !form.data.enabled}
                            onChange={(event) => form.setData('join_heading', event.target.value)}
                            error={Boolean(form.errors.join_heading)}
                            helperText={form.errors.join_heading}
                        />
                        <TextField
                            label="Short text"
                            fullWidth
                            multiline
                            minRows={2}
                            value={form.data.join_blurb}
                            disabled={!canUpdate || !form.data.enabled}
                            onChange={(event) => form.setData('join_blurb', event.target.value)}
                            error={Boolean(form.errors.join_blurb)}
                            helperText={form.errors.join_blurb}
                        />
                        {join_url ? (
                            <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center">
                                {join_qr ? <img src={join_qr} alt="Loyalty join QR" className="h-40 w-40 rounded border border-slate-200 bg-white p-2" /> : null}
                                <div className="space-y-1 text-sm">
                                    <p className="font-medium text-slate-800">Print or show this QR at the counter</p>
                                    <a href={join_url} className="break-all text-brand-700 hover:underline" target="_blank" rel="noreferrer">
                                        {join_url}
                                    </a>
                                </div>
                            </div>
                        ) : (
                            <p className="text-sm text-slate-600">Add a website domain for this business to get a join QR.</p>
                        )}
                    </CardContent>
                </Card>

                {canUpdate ? (
                    <div className="flex justify-end">
                        <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                            Save settings
                        </Button>
                    </div>
                ) : null}
            </form>
        </AppLayout>
    );
}

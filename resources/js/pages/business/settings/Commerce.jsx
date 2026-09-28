import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';

function MoneyField({ form, name, label, helperText, disabled }) {
    const { currency } = useTenant();

    return (
        <TextField
            label={label}
            type="number"
            fullWidth
            value={form.data[name] ?? ''}
            disabled={disabled}
            onChange={(event) => form.setData(name, event.target.value)}
            error={Boolean(form.errors[name])}
            helperText={form.errors[name] ?? helperText}
            slotProps={{ htmlInput: { min: 0, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
        />
    );
}

export default function Commerce({ online }) {
    const { can, hasModule } = useTenant();
    const canUpdate = can('settings.update');
    const form = useForm({
        enabled: online.enabled,
        auto_confirm: online.auto_confirm,
        pickup: online.pickup,
        delivery: online.delivery,
        delivery_fee: online.delivery_fee ?? '',
        free_delivery_over: online.free_delivery_over ?? '',
        min_order: online.min_order ?? '',
        delivery_note: online.delivery_note ?? '',
    });
    const locked = !canUpdate || !form.data.enabled;

    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/commerce', { preserveScroll: true });
    };

    return (
        <AppLayout title="Order settings">
            <Button component={Link} href="/orders" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Orders
            </Button>
            <PageHeader
                title="Order settings"
                description={canUpdate ? 'Let customers order from your website, and choose pickup, delivery or both.' : 'You have view-only access.'}
            />

            <form onSubmit={submit} noValidate className="max-w-3xl space-y-4">
                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Online ordering</h2>
                        <FormControlLabel
                            control={<Switch checked={form.data.enabled} disabled={!canUpdate} onChange={(event) => form.setData('enabled', event.target.checked)} />}
                            label="Take orders from my website"
                        />
                        <p className="text-sm text-slate-600">
                            Visitors add products to a cart and send the order; you confirm it here. Payment is collected at pickup or on delivery.
                            {hasModule('website') ? (
                                <>
                                    {' '}The cart appears when the Products section is switched on under{' '}
                                    <Link href="/website" className="font-medium text-brand-700 hover:underline">
                                        Website
                                    </Link>
                                    .
                                </>
                            ) : null}
                        </p>
                        <FormControlLabel
                            control={<Switch checked={form.data.auto_confirm} disabled={locked} onChange={(event) => form.setData('auto_confirm', event.target.checked)} />}
                            label="Confirm website orders automatically"
                        />
                        <p className="text-sm text-slate-600">When off, new website orders wait as Pending until someone confirms them.</p>
                        <MoneyField form={form} name="min_order" label="Minimum order" helperText="Optional. Smaller carts cannot check out." disabled={locked} />
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-3">
                        <h2 className="font-semibold text-slate-900">Pickup and delivery</h2>
                        <div className="flex flex-wrap gap-4">
                            <FormControlLabel
                                control={<Switch checked={form.data.pickup} disabled={locked} onChange={(event) => form.setData('pickup', event.target.checked)} />}
                                label="Pickup from the store"
                            />
                            <FormControlLabel
                                control={<Switch checked={form.data.delivery} disabled={locked} onChange={(event) => form.setData('delivery', event.target.checked)} />}
                                label="Local delivery"
                            />
                        </div>
                        {form.errors.pickup ? <p className="text-sm text-red-600">{form.errors.pickup}</p> : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <MoneyField form={form} name="delivery_fee" label="Delivery fee" helperText="A flat fee added to delivery orders." disabled={locked || !form.data.delivery} />
                            <MoneyField
                                form={form}
                                name="free_delivery_over"
                                label="Free delivery from"
                                helperText="Optional. Orders at or above this subtotal deliver free."
                                disabled={locked || !form.data.delivery}
                            />
                        </div>
                        <TextField
                            label="Delivery note"
                            fullWidth
                            value={form.data.delivery_note}
                            disabled={locked}
                            onChange={(event) => form.setData('delivery_note', event.target.value)}
                            error={Boolean(form.errors.delivery_note)}
                            helperText={form.errors.delivery_note ?? 'Shown at checkout, e.g. "We deliver within 5 km, usually the same day."'}
                            slotProps={{ htmlInput: { maxLength: 200 } }}
                        />
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

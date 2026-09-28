import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import PageHeader from '@/components/PageHeader';
import AppLayout from '@/layouts/AppLayout';

export default function Details({ details, socialNetworks, canManage }) {
    const form = useForm({ ...details });

    const submit = (event) => {
        event.preventDefault();
        form.put('/website/details', { preserveScroll: true });
    };

    const text = (name, label, props = {}) => (
        <TextField
            label={label}
            fullWidth
            disabled={!canManage}
            value={form.data[name] ?? ''}
            onChange={(event) => form.setData(name, event.target.value)}
            error={Boolean(form.errors[name])}
            helperText={form.errors[name] ?? props.help}
            slotProps={{ htmlInput: { maxLength: props.max } }}
            multiline={Boolean(props.rows)}
            minRows={props.rows}
            required={props.required}
            type={props.type}
        />
    );

    return (
        <AppLayout title="Business details">
            <Button component={Link} href="/website" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Website
            </Button>
            <PageHeader
                title="Business details"
                description={canManage ? 'Shown on your website. Your phone and address are also used in automated messages.' : 'You have view-only access.'}
            />

            <form onSubmit={submit} className="grid gap-4 lg:grid-cols-2">
                <Card variant="outlined">
                    <CardContent className="space-y-4">
                        <h2 className="font-semibold text-slate-900">About the business</h2>
                        {text('business_name', 'Business name', { max: 120, required: true })}
                        {text('tagline', 'Tagline', { max: 120, help: 'One line under your name, e.g. "Hair, skin and bridal studio".' })}
                        {text('description', 'Description', { max: 2000, rows: 4, help: 'Used in the About section when it has no text of its own.' })}
                        {text('opening_hours', 'Opening hours', { max: 500, rows: 3, help: 'For example "Mon–Sat 10 am – 8 pm, Sunday closed".' })}
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-4">
                        <h2 className="font-semibold text-slate-900">Contact</h2>
                        {text('phone', 'Phone', { max: 20, type: 'tel' })}
                        {text('whatsapp', 'WhatsApp number', { max: 20, type: 'tel', help: 'Adds a WhatsApp chat button to your website.' })}
                        {text('email', 'Email', { max: 255, type: 'email' })}
                        {text('address', 'Address', { max: 255 })}
                        {text('city', 'City', { max: 80 })}
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-4">
                        <h2 className="font-semibold text-slate-900">Social links</h2>
                        {Object.entries(socialNetworks).map(([key, label]) => (
                            <TextField
                                key={key}
                                label={label}
                                fullWidth
                                disabled={!canManage}
                                placeholder="https://"
                                value={form.data.social?.[key] ?? ''}
                                onChange={(event) => form.setData('social', { ...form.data.social, [key]: event.target.value })}
                                error={Boolean(form.errors[`social.${key}`])}
                                helperText={form.errors[`social.${key}`]}
                                slotProps={{ htmlInput: { maxLength: 255 } }}
                            />
                        ))}
                    </CardContent>
                </Card>

                <Card variant="outlined">
                    <CardContent className="space-y-4">
                        <h2 className="font-semibold text-slate-900">Search engines</h2>
                        <p className="text-sm text-slate-600">How your website appears in Google results and link previews.</p>
                        {text('seo_title', 'Page title', { max: 70, help: 'Leave empty to use the business name.' })}
                        {text('seo_description', 'Description', { max: 160, rows: 2, help: 'Leave empty to use the tagline. Up to 160 characters.' })}
                    </CardContent>
                </Card>

                {canManage ? (
                    <div className="flex justify-end lg:col-span-2">
                        <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                            Save details
                        </Button>
                    </div>
                ) : null}
            </form>
        </AppLayout>
    );
}

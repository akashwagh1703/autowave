import { useForm } from '@inertiajs/react';
import AccessTimeIcon from '@mui/icons-material/AccessTime';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import DirectionsIcon from '@mui/icons-material/Directions';
import EmailIcon from '@mui/icons-material/Email';
import PhoneIcon from '@mui/icons-material/Phone';
import PlaceIcon from '@mui/icons-material/Place';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import { ActionButton, Card, Field, Honeypot, Section, SectionHeading, inputClass, useSite } from './site';

function ContactDetails({ config }) {
    const { contact, theme } = useSite();
    const place = [contact.address, contact.city].filter(Boolean).join(', ');
    const rows = [
        contact.phone && { icon: PhoneIcon, label: contact.phone, href: contact.phone_href },
        contact.whatsapp_url && { icon: WhatsAppIcon, label: 'Chat on WhatsApp', href: contact.whatsapp_url, external: true },
        contact.email && { icon: EmailIcon, label: contact.email, href: `mailto:${contact.email}` },
        place && { icon: PlaceIcon, label: place },
        contact.opening_hours && { icon: AccessTimeIcon, label: contact.opening_hours },
    ].filter(Boolean);

    if (rows.length === 0) {
        return null;
    }

    return (
        <Card>
            <ul className="space-y-4">
                {rows.map(({ icon: Icon, label, href, external }) => (
                    <li key={label} className="flex items-start gap-3">
                        <Icon fontSize="small" style={{ color: theme.color }} className="mt-0.5 shrink-0" />
                        {href ? (
                            <a href={href} target={external ? '_blank' : undefined} rel={external ? 'noopener noreferrer' : undefined} className="break-words text-slate-700 hover:underline">
                                {label}
                            </a>
                        ) : (
                            <span className="break-words whitespace-pre-line text-slate-700">{label}</span>
                        )}
                    </li>
                ))}
            </ul>
            {config.show_map && contact.map_url ? (
                <ActionButton href={contact.map_url} variant="secondary" target="_blank" rel="noopener noreferrer" className="mt-6">
                    <DirectionsIcon fontSize="small" /> Get directions
                </ActionButton>
            ) : null}
        </Card>
    );
}

function EnquiryForm({ config }) {
    const { enquiry, enquirySent } = useSite();
    const form = useForm({ name: '', phone: '', email: '', interest: '', message: '', company_website: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/enquiry', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    if (enquirySent && !form.isDirty) {
        return (
            <Card className="flex flex-col items-center justify-center py-10 text-center">
                <CheckCircleIcon className="text-emerald-600" sx={{ fontSize: 48 }} />
                <p className="mt-3 text-lg font-semibold text-slate-900" role="status">
                    {config.success_message}
                </p>
            </Card>
        );
    }

    return (
        <Card>
            <h3 className="text-lg font-semibold text-slate-900">{config.form_heading}</h3>
            <form onSubmit={submit} className="relative mt-4 space-y-4" noValidate>
                <Honeypot value={form.data.company_website} onChange={(value) => form.setData('company_website', value)} />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Name" required error={form.errors.name}>
                        <input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} autoComplete="name" maxLength={120} required />
                    </Field>
                    <Field label="Phone" required error={form.errors.phone}>
                        <input
                            className={inputClass}
                            type="tel"
                            value={form.data.phone}
                            onChange={(event) => form.setData('phone', event.target.value)}
                            autoComplete="tel"
                            maxLength={20}
                            required
                        />
                    </Field>
                </div>
                <Field label="Email" error={form.errors.email}>
                    <input className={inputClass} type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} autoComplete="email" maxLength={255} />
                </Field>
                {enquiry.interests.length ? (
                    <Field label="Interested in" error={form.errors.interest}>
                        <select className={inputClass} value={form.data.interest} onChange={(event) => form.setData('interest', event.target.value)}>
                            <option value="">Choose (optional)</option>
                            {enquiry.interests.map((interest) => (
                                <option key={interest} value={interest}>
                                    {interest}
                                </option>
                            ))}
                        </select>
                    </Field>
                ) : null}
                <Field label="Message" error={form.errors.message}>
                    <textarea className={inputClass} rows={4} value={form.data.message} onChange={(event) => form.setData('message', event.target.value)} maxLength={2000} />
                </Field>
                {form.errors.throttle ? (
                    <p className="text-sm text-red-600" role="alert">
                        {form.errors.throttle}
                    </p>
                ) : null}
                <ActionButton type="submit" disabled={form.processing} className="w-full sm:w-auto">
                    {form.processing ? 'Sending…' : 'Send'}
                </ActionButton>
            </form>
        </Card>
    );
}

export default function ContactSection({ config }) {
    const { enquiry } = useSite();
    const showForm = Boolean(enquiry?.enabled);

    return (
        <Section id="contact" tone="muted">
            <SectionHeading title={config.heading} />
            <div className={`grid gap-6 ${showForm ? 'md:grid-cols-2' : 'mx-auto max-w-xl'}`}>
                <ContactDetails config={config} />
                {showForm ? <EnquiryForm config={config} /> : null}
            </div>
        </Section>
    );
}

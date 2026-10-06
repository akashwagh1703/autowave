import { useForm } from '@inertiajs/react';
import AccessTimeIcon from '@mui/icons-material/AccessTime';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import DirectionsIcon from '@mui/icons-material/Directions';
import EmailIcon from '@mui/icons-material/Email';
import PhoneIcon from '@mui/icons-material/Phone';
import PlaceIcon from '@mui/icons-material/Place';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import { alpha } from '@/utils/websiteTheme';
import { ActionButton, Card, Field, Honeypot, Section, SectionHeading, headingStyle, inputClass, useSite } from './site';

function ContactDetails({ config }) {
    const { contact, theme } = useSite();
    const place = [contact.address, contact.city].filter(Boolean).join(', ');
    const rows = [
        contact.phone && { icon: PhoneIcon, title: 'Call us', label: contact.phone, href: contact.phone_href },
        contact.whatsapp_url && { icon: WhatsAppIcon, title: 'WhatsApp', label: 'Chat with us', href: contact.whatsapp_url, external: true },
        contact.email && { icon: EmailIcon, title: 'Email', label: contact.email, href: `mailto:${contact.email}` },
        place && { icon: PlaceIcon, title: 'Address', label: place },
        contact.opening_hours && { icon: AccessTimeIcon, title: 'Opening hours', label: contact.opening_hours },
    ].filter(Boolean);

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="space-y-6">
            <ul className="grid gap-4 *:min-w-0 sm:grid-cols-2">
                {rows.map(({ icon: Icon, title, label, href, external }, index) => (
                    <li key={title} className={rows.length % 2 === 1 && index === rows.length - 1 ? 'sm:col-span-2' : ''}>
                        <Card className="flex h-full items-start gap-4 p-5">
                            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: alpha(theme.color, 0.1), color: theme.color }}>
                                <Icon fontSize="small" />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-xs font-semibold tracking-wide text-slate-500 uppercase">{title}</span>
                                {href ? (
                                    <a
                                        href={href}
                                        target={external ? '_blank' : undefined}
                                        rel={external ? 'noopener noreferrer' : undefined}
                                        className="mt-1 block font-semibold break-words text-slate-900 hover:underline"
                                    >
                                        {label}
                                    </a>
                                ) : (
                                    <span className="mt-1 block font-medium break-words whitespace-pre-line text-slate-900">{label}</span>
                                )}
                            </span>
                        </Card>
                    </li>
                ))}
            </ul>
            {config.show_map && place ? <MapCard place={place} /> : null}
        </div>
    );
}

/** Google Maps embed of the business address (no API key needed), with a directions link. */
function MapCard({ place }) {
    const { contact, business, theme } = useSite();
    const query = encodeURIComponent(`${business.name}, ${place}`);

    return (
        <div className="overflow-hidden border border-slate-200/70 bg-white shadow-sm" style={{ borderRadius: theme.radius }}>
            <iframe
                title={`Map showing ${business.name}`}
                src={`https://www.google.com/maps?q=${query}&output=embed`}
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
                className="block h-56 w-full border-0 grayscale-[30%]"
            />
            {contact.map_url ? (
                <div className="flex items-center justify-between gap-3 px-5 py-3">
                    <span className="truncate text-sm text-slate-600">{place}</span>
                    <a href={contact.map_url} target="_blank" rel="noopener noreferrer" className="flex shrink-0 items-center gap-1 text-sm font-semibold" style={{ color: theme.color }}>
                        <DirectionsIcon sx={{ fontSize: 18 }} /> Get directions
                    </a>
                </div>
            ) : null}
        </div>
    );
}

function EnquiryForm({ config }) {
    const { enquiry, enquirySent, theme } = useSite();
    const form = useForm({ name: '', phone: '', email: '', interest: '', message: '', company_website: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/enquiry', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    if (enquirySent && !form.isDirty) {
        return (
            <Card className="flex h-full flex-col items-center justify-center py-14 text-center">
                <CheckCircleIcon className="text-emerald-600" sx={{ fontSize: 56 }} />
                <p className="mt-4 text-xl font-semibold text-slate-900" role="status">
                    {config.success_message}
                </p>
            </Card>
        );
    }

    return (
        <Card className="p-7 sm:p-9">
            <h3 className="text-2xl text-slate-900" style={headingStyle(theme)}>
                {config.form_heading}
            </h3>
            <p className="mt-1 text-sm text-slate-500">We reply as soon as we can, usually the same day.</p>
            <form onSubmit={submit} className="relative mt-6 space-y-4" noValidate>
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
                <ActionButton type="submit" size="lg" disabled={form.processing} className="w-full">
                    {form.processing ? 'Sending…' : 'Send message'}
                </ActionButton>
            </form>
        </Card>
    );
}

export default function ContactSection({ config }) {
    const { enquiry } = useSite();
    const showForm = Boolean(enquiry?.enabled);

    return (
        <Section id="contact">
            <SectionHeading title={config.heading} />
            <div className={`grid gap-8 *:min-w-0 ${showForm ? 'lg:grid-cols-[1.1fr_0.9fr]' : 'mx-auto max-w-3xl'}`}>
                <ContactDetails config={config} />
                {showForm ? <EnquiryForm config={config} /> : null}
            </div>
        </Section>
    );
}

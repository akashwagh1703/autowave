import { useForm } from '@inertiajs/react';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import Button from '@mui/material/Button';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import PublicLayout from '@/layouts/PublicLayout';
import { CheckList, Container, Eyebrow } from '@/modules/marketing/blocks';

const HONEYPOT = 'company_website';

export default function Demo({ industries, requested, whatsappUrl }) {
    const form = useForm({ name: '', phone: '', email: '', business_name: '', industry: '', city: '', message: '', [HONEYPOT]: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/demo', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const field = (name) => ({
        name,
        value: form.data[name],
        onChange: (event) => form.setData(name, event.target.value),
        error: Boolean(form.errors[name]),
        helperText: form.errors[name],
        fullWidth: true,
    });

    return (
        <PublicLayout>
            <section className="relative -mt-16 overflow-hidden pt-16">
                <div aria-hidden="true" className="pointer-events-none absolute -top-40 left-1/2 -z-10 h-[34rem] w-[56rem] -translate-x-1/2 rounded-full bg-gradient-to-br from-brand-200/60 to-accent-300/30 blur-3xl" />
                <Container className="grid gap-12 pt-12 pb-20 lg:grid-cols-2 lg:pt-20">
                    <div>
                        <Eyebrow>Free demo</Eyebrow>
                        <h1 className="font-display mt-2 text-4xl leading-tight font-extrabold tracking-tight text-ink sm:text-5xl">See AutoWave set up for your business</h1>
                        <p className="mt-5 text-lg text-slate-600">
                            In a 20-minute call we will show you how AutoWave works for a business like yours and answer every question. No obligation.
                        </p>
                        <CheckList
                            className="mt-8"
                            items={[
                                'Your website, live with your business type',
                                'How online bookings or orders reach you',
                                'Following up leads and WhatsApp messages',
                                'Which plan fits you, and what it costs',
                            ]}
                        />
                        {whatsappUrl ? (
                            <a
                                href={whatsappUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-8 inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-ink ring-1 ring-slate-200 hover:bg-slate-50"
                            >
                                <WhatsAppIcon sx={{ color: '#25D366' }} /> Prefer WhatsApp? Chat with us
                            </a>
                        ) : null}
                    </div>

                    <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl shadow-brand-900/10 sm:p-8">
                        {requested ? (
                            <div className="flex h-full flex-col items-center justify-center py-10 text-center" role="status">
                                <CheckCircleIcon sx={{ fontSize: 56 }} className="text-accent-500" />
                                <h2 className="font-display mt-4 text-2xl font-extrabold text-ink">Thank you! We will call you soon.</h2>
                                <p className="mt-2 max-w-sm text-slate-600">Our team usually calls back within one working day to fix a time that suits you.</p>
                            </div>
                        ) : (
                            <form onSubmit={submit} noValidate>
                                <h2 className="font-display text-xl font-extrabold text-ink">Book your free demo</h2>
                                <p className="mt-1 text-sm text-slate-500">We will call you to fix a time.</p>
                                <div className="mt-6 grid gap-4 sm:grid-cols-2">
                                    <TextField {...field('name')} label="Your name" required autoComplete="name" />
                                    <TextField {...field('phone')} label="Phone (WhatsApp)" required type="tel" autoComplete="tel" />
                                    <TextField {...field('business_name')} label="Business name" required autoComplete="organization" />
                                    <TextField {...field('industry')} select label="Business type">
                                        <MenuItem value="">Choose (optional)</MenuItem>
                                        {industries.map((industry) => (
                                            <MenuItem key={industry} value={industry}>
                                                {industry}
                                            </MenuItem>
                                        ))}
                                    </TextField>
                                    <TextField {...field('city')} label="City" autoComplete="address-level2" />
                                    <TextField {...field('email')} label="Email" type="email" autoComplete="email" />
                                    <TextField {...field('message')} label="Anything we should know? (optional)" multiline minRows={3} className="sm:col-span-2" />
                                </div>
                                <input
                                    type="text"
                                    name={HONEYPOT}
                                    value={form.data[HONEYPOT]}
                                    onChange={(event) => form.setData(HONEYPOT, event.target.value)}
                                    tabIndex={-1}
                                    autoComplete="off"
                                    aria-hidden="true"
                                    className="absolute -left-[9999px] h-0 w-0 opacity-0"
                                />
                                {form.errors.throttle ? <p className="mt-4 text-sm text-red-600">{form.errors.throttle}</p> : null}
                                <Button type="submit" variant="contained" size="large" fullWidth disabled={form.processing} sx={{ mt: 3, py: 1.5, borderRadius: 3 }}>
                                    {form.processing ? 'Sending…' : 'Request my demo'}
                                </Button>
                                <p className="mt-3 text-center text-xs text-slate-500">
                                    We only use your details to contact you about AutoWave. See our{' '}
                                    <a href="/privacy" className="underline">
                                        Privacy policy
                                    </a>
                                    .
                                </p>
                            </form>
                        )}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

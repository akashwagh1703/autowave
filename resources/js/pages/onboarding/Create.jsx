import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Step from '@mui/material/Step';
import StepLabel from '@mui/material/StepLabel';
import Stepper from '@mui/material/Stepper';
import StorefrontIcon from '@mui/icons-material/Storefront';
import EmptyState from '@/components/EmptyState';
import FlashMessages from '@/components/FlashMessages';
import VerifyEmailBanner from '@/components/VerifyEmailBanner';
import BrandingStep from '@/components/onboarding/BrandingStep';
import BusinessTypeStep from '@/components/onboarding/BusinessTypeStep';
import CapabilitiesStep from '@/components/onboarding/CapabilitiesStep';
import DetailsStep from '@/components/onboarding/DetailsStep';
import ReviewStep from '@/components/onboarding/ReviewStep';
import TemplateStep from '@/components/onboarding/TemplateStep';
import { indexByCode, withDependencies } from '@/components/onboarding/modules';
import { slugify } from '@/components/onboarding/slug';

const STEPS = ['Business type', 'Details', 'Capabilities', 'Branding', 'Website', 'Review'];

// Server error keys → the step that owns the field.
const FIELD_STEP = {
    business_type: 0,
    name: 1,
    slug: 1,
    phone: 1,
    email: 1,
    city: 1,
    address: 1,
    description: 1,
    modules: 2,
    primary_color: 3,
    tagline: 3,
    website_template: 4,
};

const PHONE = /^\+?[0-9][0-9\-\s]{6,19}$/;
const HEX = /^#[0-9a-fA-F]{6}$/;

function stepForError(key) {
    return FIELD_STEP[key.split('.')[0]] ?? STEPS.length - 1;
}

export default function Create({ catalog, limitReached, hasWorkspaces, defaults }) {
    const { app } = usePage().props;
    const [step, setStep] = useState(0);
    const [slugTouched, setSlugTouched] = useState(false);
    const [slugStatus, setSlugStatus] = useState({ state: 'idle' });
    const slugRequest = useRef(0);

    const modulesByCode = useMemo(() => indexByCode(catalog.modules), [catalog.modules]);
    const typesByCode = useMemo(() => indexByCode(catalog.business_types), [catalog.business_types]);

    const form = useForm({
        business_type: '',
        name: '',
        slug: '',
        phone: '',
        email: defaults.email ?? '',
        city: '',
        address: '',
        description: '',
        modules: [],
        primary_color: catalog.brand_colors[0] ?? '#4f46e5',
        tagline: '',
        website_template: '',
    });

    const businessType = typesByCode[form.data.business_type];
    const template = catalog.templates.find((item) => item.code === form.data.website_template);

    useEffect(() => {
        const slug = form.data.slug;
        const request = ++slugRequest.current;

        if (!slug) {
            setSlugStatus({ state: 'idle' });
            return undefined;
        }

        setSlugStatus({ state: 'checking' });
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/onboarding/slug?slug=${encodeURIComponent(slug)}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const result = await response.json();
                if (request === slugRequest.current) {
                    setSlugStatus({
                        state: result.available ? 'available' : result.valid ? 'taken' : 'invalid',
                        suggestion: result.suggestion,
                        domain: result.domain,
                    });
                }
            } catch {
                if (request === slugRequest.current) {
                    setSlugStatus({ state: 'error' });
                }
            }
        }, 400);

        return () => clearTimeout(timer);
    }, [form.data.slug]);

    const chooseType = (code) => {
        if (code === form.data.business_type) {
            return;
        }
        const type = typesByCode[code];
        form.setData((data) => ({
            ...data,
            business_type: code,
            modules: withDependencies([...type.modules, ...type.required_modules], modulesByCode),
            website_template: type.templates[0] ?? catalog.templates[0]?.code ?? '',
        }));
        form.clearErrors('business_type', 'modules', 'website_template');
    };

    const changeName = (name) => {
        form.setData((data) => ({ ...data, name, slug: slugTouched ? data.slug : slugify(name) }));
        form.clearErrors('name');
    };

    const changeSlug = (value) => {
        setSlugTouched(true);
        form.setData('slug', value.toLowerCase().replace(/\s+/g, '-'));
        form.clearErrors('slug');
    };

    const validateStep = (index) => {
        const errors = {};
        const data = form.data;

        if (index === 0 && !data.business_type) {
            errors.business_type = 'Choose your business type to continue.';
        }
        if (index === 1) {
            if (data.name.trim().length < 2) errors.name = 'Enter your business name.';
            if (!data.slug) errors.slug = 'Choose a web address.';
            else if (['taken', 'invalid'].includes(slugStatus.state)) errors.slug = 'Choose an available web address.';
            if (!PHONE.test(data.phone.trim())) errors.phone = 'Enter a valid phone number.';
            if (!data.city.trim()) errors.city = 'Enter your city.';
        }
        if (index === 3 && data.primary_color && !HEX.test(data.primary_color)) {
            errors.primary_color = 'Use a hex colour such as #4f46e5.';
        }
        if (index === 4 && !data.website_template) {
            errors.website_template = 'Pick a website style.';
        }

        form.clearErrors();
        if (Object.keys(errors).length) {
            form.setError(errors);
            return false;
        }
        return true;
    };

    const next = () => {
        if (validateStep(step)) {
            setStep((current) => Math.min(current + 1, STEPS.length - 1));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    const back = () => setStep((current) => Math.max(current - 1, 0));

    const goTo = (index) => {
        if (index < step || [...Array(index).keys()].every((previous) => validateStep(previous))) {
            setStep(index);
        }
    };

    const submit = () => {
        form.post('/onboarding', {
            preserveScroll: true,
            onError: (errors) => {
                const keys = Object.keys(errors);
                if (keys.length) {
                    setStep(Math.min(...keys.map(stepForError)));
                }
            },
        });
    };

    const errorsFor = (index) => Object.keys(form.errors).some((key) => stepForError(key) === index);

    const header = (
        <header className="border-b border-slate-200 bg-white">
            <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3 sm:px-6">
                <span className="text-lg font-bold text-brand-700">{app.name}</span>
                <div className="flex items-center gap-1">
                    {hasWorkspaces ? (
                        <Button size="small" color="inherit" component={Link} href="/workspaces">
                            Your businesses
                        </Button>
                    ) : null}
                    <Button size="small" color="inherit" onClick={() => router.post('/logout')}>
                        Log out
                    </Button>
                </div>
            </div>
        </header>
    );

    if (limitReached) {
        return (
            <div className="min-h-screen bg-slate-50">
                <Head title="Set up your business" />
                {header}
                <main className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
                    <EmptyState
                        icon={StorefrontIcon}
                        title="Business limit reached"
                        description="You've created the maximum number of businesses for one account. Contact support if you need more."
                        action={
                            hasWorkspaces ? (
                                <Button variant="contained" component={Link} href="/workspaces">
                                    Go to your businesses
                                </Button>
                            ) : null
                        }
                    />
                </main>
            </div>
        );
    }

    const generalError = form.errors.business ?? null;

    return (
        <div className="min-h-screen bg-slate-50">
            <Head title="Set up your business" />
            <VerifyEmailBanner />
            {header}

            <main className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">Set up your business</h1>
                    <p className="mt-1 text-sm text-slate-600">
                        A few quick questions and your workspace, website and web address are ready. No credit card needed.
                    </p>
                </div>

                <Stepper activeStep={step} alternativeLabel className="mb-6 hidden sm:flex">
                    {STEPS.map((label, index) => (
                        <Step key={label} completed={index < step}>
                            <StepLabel error={errorsFor(index)} onClick={() => goTo(index)} className="cursor-pointer">
                                {label}
                            </StepLabel>
                        </Step>
                    ))}
                </Stepper>
                <p className="mb-4 text-sm font-medium text-slate-600 sm:hidden">
                    Step {step + 1} of {STEPS.length}: {STEPS[step]}
                </p>

                <Card variant="outlined">
                    <CardContent className="p-5 sm:p-8">
                        {step === 0 ? (
                            <BusinessTypeStep
                                types={catalog.business_types}
                                value={form.data.business_type}
                                onChange={chooseType}
                                error={form.errors.business_type}
                            />
                        ) : null}
                        {step === 1 ? (
                            <DetailsStep
                                form={form}
                                slugStatus={slugStatus}
                                domainSuffix={catalog.domain_suffix}
                                onNameChange={changeName}
                                onSlugChange={changeSlug}
                            />
                        ) : null}
                        {step === 2 ? (
                            <CapabilitiesStep
                                modules={catalog.modules}
                                modulesByCode={modulesByCode}
                                businessType={businessType}
                                selected={form.data.modules}
                                onChange={(modules) => form.setData('modules', modules)}
                                error={form.errors.modules ?? Object.entries(form.errors).find(([key]) => key.startsWith('modules.'))?.[1]}
                            />
                        ) : null}
                        {step === 3 ? <BrandingStep form={form} colors={catalog.brand_colors} /> : null}
                        {step === 4 ? (
                            <TemplateStep
                                templates={catalog.templates}
                                recommended={businessType?.templates ?? []}
                                value={form.data.website_template}
                                onChange={(code) => form.setData('website_template', code)}
                                color={HEX.test(form.data.primary_color) ? form.data.primary_color : '#4f46e5'}
                                name={form.data.name}
                                error={form.errors.website_template}
                            />
                        ) : null}
                        {step === 5 ? (
                            <ReviewStep
                                data={form.data}
                                businessType={businessType}
                                modulesByCode={modulesByCode}
                                template={template}
                                domain={`${form.data.slug}${catalog.domain_suffix}`}
                                generalError={generalError}
                                onEdit={setStep}
                            />
                        ) : null}

                        <div className="mt-8 flex items-center justify-between gap-3 border-t border-slate-100 pt-5">
                            <Button onClick={back} disabled={step === 0 || form.processing}>
                                Back
                            </Button>
                            {step < STEPS.length - 1 ? (
                                <Button variant="contained" onClick={next}>
                                    Continue
                                </Button>
                            ) : (
                                <Button variant="contained" size="large" onClick={submit} disabled={form.processing}>
                                    {form.processing ? 'Creating your workspace…' : 'Create my business'}
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </main>

            <FlashMessages />
        </div>
    );
}

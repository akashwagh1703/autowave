import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import PageHeader from '@/components/PageHeader';
import { TemplatePreview } from '@/components/onboarding/TemplateStep';
import AppLayout from '@/layouts/AppLayout';
import MediaManager from '@/modules/website/MediaManager';

const HEX = /^#[0-9a-fA-F]{6}$/;

export default function Design({ design, businessName, templates, logo, logoRules, canManage }) {
    const form = useForm({ ...design });
    const color = HEX.test(form.data.primary_color) ? form.data.primary_color : design.primary_color;

    const submit = (event) => {
        event.preventDefault();
        form.put('/website/design', { preserveScroll: true });
    };

    return (
        <AppLayout title="Website design">
            <Button component={Link} href="/website" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Website
            </Button>
            <PageHeader title="Design and logo" description={canManage ? 'Templates only change the look; your content stays the same.' : 'You have view-only access.'} />

            <div className="grid gap-4 lg:grid-cols-3">
                <form onSubmit={submit} className="lg:col-span-2">
                    <Card variant="outlined">
                        <CardContent className="space-y-5">
                            <div>
                                <h2 className="mb-3 font-semibold text-slate-900">Template</h2>
                                <div role="radiogroup" aria-label="Website template" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {templates.map((template) => {
                                        const selected = form.data.template === template.code;

                                        return (
                                            <button
                                                key={template.code}
                                                type="button"
                                                role="radio"
                                                aria-checked={selected}
                                                disabled={!canManage}
                                                onClick={() => form.setData('template', template.code)}
                                                className={`relative overflow-hidden rounded-xl border text-left transition ${selected ? 'border-brand-600 ring-1 ring-brand-600' : 'border-slate-200 hover:border-brand-500'}`}
                                            >
                                                <TemplatePreview template={template} color={color} name={businessName} />
                                                <div className="p-3">
                                                    <span className="font-semibold text-slate-900">{template.name}</span>
                                                    <p className="mt-1 text-xs text-slate-600">{template.description}</p>
                                                </div>
                                                {selected ? <CheckCircleIcon className="absolute top-2 right-2 rounded-full bg-white text-brand-600" fontSize="small" /> : null}
                                            </button>
                                        );
                                    })}
                                </div>
                                {form.errors.template ? <p className="mt-2 text-sm text-red-600">{form.errors.template}</p> : null}
                            </div>

                            <div className="flex flex-wrap items-start gap-3">
                                <input
                                    type="color"
                                    aria-label="Pick brand colour"
                                    value={color}
                                    disabled={!canManage}
                                    onChange={(event) => form.setData('primary_color', event.target.value)}
                                    className="h-10 w-14 cursor-pointer rounded border border-slate-300"
                                />
                                <TextField
                                    size="small"
                                    label="Brand colour"
                                    disabled={!canManage}
                                    value={form.data.primary_color}
                                    onChange={(event) => form.setData('primary_color', event.target.value)}
                                    error={Boolean(form.errors.primary_color)}
                                    helperText={form.errors.primary_color ?? 'Used for buttons, headings and highlights.'}
                                    slotProps={{ htmlInput: { maxLength: 7 } }}
                                />
                            </div>

                            {canManage ? (
                                <div className="flex justify-end">
                                    <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                                        Save design
                                    </Button>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                </form>

                <Card variant="outlined" className="self-start">
                    <CardContent>
                        <h2 className="font-semibold text-slate-900">Logo</h2>
                        <p className="mb-3 text-sm text-slate-600">Shown in the website header. A wide image with a transparent background works best.</p>
                        <MediaManager rules={logoRules} items={logo ? [logo] : []} canManage={canManage} />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

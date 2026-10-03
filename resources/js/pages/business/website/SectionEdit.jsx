import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import PageHeader from '@/components/PageHeader';
import useTenant from '@/hooks/useTenant';
import AppLayout from '@/layouts/AppLayout';
import AttachmentsCard from '@/modules/files/AttachmentsCard';
import MediaManager from '@/modules/website/MediaManager';
import SchemaFields from '@/modules/website/SchemaFields';

export default function SectionEdit({ section, fields, media, files, canManage }) {
    const { timezone } = useTenant();
    const form = useForm({ config: section.config });

    const submit = (event) => {
        event.preventDefault();
        form.put(`/website/sections/${section.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout title={`${section.label} section`}>
            <Button component={Link} href="/website" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Website
            </Button>
            <PageHeader title={`${section.label} section`} description={canManage ? section.description : 'You have view-only access.'} />

            <div className="grid gap-4 lg:grid-cols-3">
                {fields.length ? (
                    <form onSubmit={submit} className="lg:col-span-2">
                        <Card variant="outlined">
                            <CardContent className="space-y-5">
                                <SchemaFields
                                    fields={fields}
                                    values={form.data.config}
                                    onChange={(key, value) => form.setData('config', { ...form.data.config, [key]: value })}
                                    errors={form.errors}
                                    disabled={!canManage}
                                    section={section.label}
                                />
                                {canManage ? (
                                    <div className="flex justify-end">
                                        <Button type="submit" variant="contained" disabled={form.processing || !form.isDirty}>
                                            Save section
                                        </Button>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    </form>
                ) : null}

                {media ? (
                    <Card variant="outlined" className={fields.length ? 'self-start' : 'lg:col-span-2'}>
                        <CardContent>
                            <h2 className="mb-3 font-semibold text-slate-900">{media.rules.label}</h2>
                            <MediaManager rules={media.rules} items={media.items} canManage={canManage} />
                        </CardContent>
                    </Card>
                ) : null}

                {files ? (
                    <div className={fields.length ? 'self-start' : 'lg:col-span-2'}>
                        <AttachmentsCard
                            documents={files}
                            timezone={timezone}
                            title={files.kinds[0]?.kind === 'video' ? 'Videos' : 'Files'}
                            namePlaceholder={files.kinds[0]?.kind === 'video' ? 'e.g. A day at our studio' : 'e.g. Price list, Menu'}
                        />
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}

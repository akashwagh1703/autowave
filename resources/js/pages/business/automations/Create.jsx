import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import AutomationBuilder, { toPayload } from '@/modules/automations/AutomationBuilder';
import { newStep, triggerOf } from '@/modules/automations/catalog';

export default function Create({ catalog }) {
    const firstTrigger = catalog.triggers[0]?.key ?? '';
    const form = useForm({
        name: '',
        description: '',
        trigger: firstTrigger,
        is_active: true,
        once_per_subject: false,
        steps: [newStep('action', catalog, triggerOf(catalog, firstTrigger))],
    });

    const submit = (event) => {
        event.preventDefault();
        form.transform(toPayload);
        form.post('/automations', { preserveScroll: true });
    };

    return (
        <AppLayout title="New automation">
            <Button component={Link} href="/automations" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Automations
            </Button>
            <PageHeader title="New automation" description="Choose what starts it, then add conditions, waits and actions." />
            <div className="max-w-4xl">
                <AutomationBuilder form={form} catalog={catalog} onSubmit={submit} submitLabel="Create automation" cancelHref="/automations" />
            </div>
        </AppLayout>
    );
}

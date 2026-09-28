import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import AutomationBuilder, { toPayload } from '@/modules/automations/AutomationBuilder';
import { nextKey } from '@/modules/automations/catalog';

export default function Edit({ automation, definition, catalog }) {
    const form = useForm({
        ...definition,
        description: definition.description ?? '',
        steps: definition.steps.map((step) => ({ ...step, _key: nextKey() })),
    });

    const submit = (event) => {
        event.preventDefault();
        form.transform(toPayload);
        form.put(`/automations/${automation.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout title={`Edit ${automation.name}`}>
            <Button component={Link} href={`/automations/${automation.id}`} startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                {automation.name}
            </Button>
            <PageHeader title={`Edit ${automation.name}`} description="Changes apply to new runs. Runs already in progress keep their original steps." />
            {!automation.trigger_available ? (
                <Alert severity="warning" className="mb-4 max-w-4xl">
                    “{automation.trigger_label}” is not available for your business any more (a module or feature was turned off). Choose another trigger to use this automation.
                </Alert>
            ) : null}
            <div className="max-w-4xl">
                <AutomationBuilder form={form} catalog={catalog} onSubmit={submit} submitLabel="Save changes" cancelHref={`/automations/${automation.id}`} />
            </div>
        </AppLayout>
    );
}

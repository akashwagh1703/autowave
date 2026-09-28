import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ResourceForm from '@/modules/booking/ResourceForm';
import useTenant from '@/hooks/useTenant';

export default function Create({ members, services, servicesEnabled, defaultHours }) {
    const { resourceLabel } = useTenant();
    const form = useForm({
        name: '',
        description: '',
        color: '#6366f1',
        is_active: true,
        tenant_user_id: null,
        service_ids: services.map((service) => service.id),
        working_hours: defaultHours,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/resources');
    };

    const title = `Add ${resourceLabel.singular.toLowerCase()}`;

    return (
        <AppLayout title={title}>
            <Button component={Link} href="/resources" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                {resourceLabel.plural}
            </Button>
            <PageHeader title={title} description="Anyone or anything customers book time with, with their own hours and services." />
            <Card variant="outlined" className="max-w-4xl">
                <CardContent>
                    <ResourceForm
                        form={form}
                        onSubmit={submit}
                        submitLabel={title}
                        cancelHref="/resources"
                        members={members}
                        services={services}
                        servicesEnabled={servicesEnabled}
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

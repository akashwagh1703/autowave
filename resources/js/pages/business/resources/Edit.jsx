import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ResourceForm from '@/modules/booking/ResourceForm';

export default function Edit({ resource, members, services, servicesEnabled }) {
    const form = useForm({
        name: resource.name,
        description: resource.description ?? '',
        color: resource.color,
        is_active: resource.is_active,
        tenant_user_id: resource.tenant_user_id,
        service_ids: resource.service_ids ?? [],
        working_hours: resource.working_hours ?? [],
        hourly_rate: resource.hourly_rate ?? '',
        rates: resource.rates ?? [],
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(`/resources/${resource.id}`);
    };

    return (
        <AppLayout title={`Edit ${resource.name}`}>
            <Button component={Link} href={`/resources/${resource.id}`} startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                {resource.name}
            </Button>
            <PageHeader title={`Edit ${resource.name}`} />
            <Card variant="outlined" className="max-w-4xl">
                <CardContent>
                    <ResourceForm
                        form={form}
                        onSubmit={submit}
                        submitLabel="Save changes"
                        cancelHref={`/resources/${resource.id}`}
                        members={members}
                        services={services}
                        servicesEnabled={servicesEnabled}
                        resourceId={resource.id}
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

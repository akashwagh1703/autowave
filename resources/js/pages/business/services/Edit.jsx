import { Link, router, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ConfirmDialog from '@/components/ConfirmDialog';
import ServiceForm from '@/modules/services/ServiceForm';
import AttachmentsCard from '@/modules/files/AttachmentsCard';
import useTenant from '@/hooks/useTenant';

export default function Edit({ service, categories, resources, upcomingCount, files }) {
    const { timezone } = useTenant();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const form = useForm({
        name: service.name,
        service_category_id: service.service_category_id,
        duration_minutes: service.duration_minutes,
        price: service.price,
        description: service.description ?? '',
        is_active: service.is_active,
        resource_ids: service.resource_ids ?? [],
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(`/services/${service.id}`);
    };

    const destroy = () =>
        router.delete(`/services/${service.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });

    return (
        <AppLayout title={`Edit ${service.name}`}>
            <Button component={Link} href="/services" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Services
            </Button>
            <PageHeader
                title={`Edit ${service.name}`}
                description="Changes apply to new bookings. Existing appointments keep their time and price."
                actions={
                    <Button color="error" onClick={() => setConfirmDelete(true)}>
                        Delete
                    </Button>
                }
            />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent>
                    <ServiceForm
                        form={form}
                        onSubmit={submit}
                        submitLabel="Save changes"
                        cancelHref="/services"
                        categories={categories}
                        resources={resources}
                    />
                </CardContent>
            </Card>

            {files ? (
                <div className="mt-6 max-w-3xl">
                    <AttachmentsCard documents={files} timezone={timezone} title="Video and brochures" />
                </div>
            ) : null}

            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${service.name}?`}
                description={
                    upcomingCount > 0
                        ? `${upcomingCount} upcoming ${upcomingCount === 1 ? 'appointment keeps' : 'appointments keep'} this service. It can no longer be booked.`
                        : 'It can no longer be booked. Past appointments keep showing it.'
                }
                confirmLabel="Delete"
                destructive
                processing={deleting}
                onConfirm={destroy}
                onClose={() => setConfirmDelete(false)}
            />
        </AppLayout>
    );
}

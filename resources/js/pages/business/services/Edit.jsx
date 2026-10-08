import { Link, router, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ConfirmDialog from '@/components/ConfirmDialog';
import RecordImage from '@/components/RecordImage';
import ServiceForm from '@/modules/services/ServiceForm';
import AttachmentsCard from '@/modules/files/AttachmentsCard';
import useTenant from '@/hooks/useTenant';

export default function Edit({ service, categories, resources, packageOptions, upcomingCount, files }) {
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
        is_package: service.is_package ?? false,
        resource_ids: service.resource_ids ?? [],
        included_service_ids: service.included_service_ids ?? [],
        product_ids: service.product_ids ?? [],
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

    const noun = service.is_package ? 'package' : 'service';

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
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
                <div className="space-y-6">
                    <Card variant="outlined">
                        <CardContent>
                            <ServiceForm
                                form={form}
                                onSubmit={submit}
                                submitLabel="Save changes"
                                cancelHref="/services"
                                categories={categories}
                                resources={resources}
                                packageOptions={packageOptions}
                            />
                        </CardContent>
                    </Card>
                    {files ? <AttachmentsCard documents={files} timezone={timezone} title="Video and brochures" namePlaceholder="e.g. Package details, Before and after" /> : null}
                </div>
                <div>
                    <Card variant="outlined">
                        <CardContent>
                            <RecordImage
                                image={service.image}
                                alt={service.name}
                                endpoint={`/services/${service.id}/image`}
                                title="Photo"
                                help="JPG or PNG. Shown as a card when customers browse services in the WhatsApp assistant."
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${service.name}?`}
                description={
                    upcomingCount > 0
                        ? `${upcomingCount} upcoming ${upcomingCount === 1 ? 'appointment keeps' : 'appointments keep'} this ${noun}. It can no longer be booked.`
                        : `It can no longer be booked. Past appointments keep showing it.`
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

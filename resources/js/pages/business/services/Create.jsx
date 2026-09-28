import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ServiceForm from '@/modules/services/ServiceForm';

export default function Create({ categories, resources, defaultCategoryId }) {
    const form = useForm({
        name: '',
        service_category_id: defaultCategoryId,
        duration_minutes: 30,
        price: '',
        description: '',
        is_active: true,
        resource_ids: resources.map((resource) => resource.id),
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/services');
    };

    return (
        <AppLayout title="Add service">
            <Button component={Link} href="/services" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Services
            </Button>
            <PageHeader title="Add service" description="What you offer, how long it takes and what it costs." />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent>
                    <ServiceForm form={form} onSubmit={submit} submitLabel="Add service" cancelHref="/services" categories={categories} resources={resources} />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

import { Link, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import ServiceForm from '@/modules/services/ServiceForm';

export default function Create({ categories, resources, packageOptions, defaultCategoryId, asPackage = false }) {
    const form = useForm({
        name: '',
        service_category_id: defaultCategoryId,
        duration_minutes: asPackage ? 60 : 30,
        price: '',
        description: '',
        is_active: true,
        is_package: asPackage,
        resource_ids: resources.map((resource) => resource.id),
        included_service_ids: [],
        product_ids: [],
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/services');
    };

    const title = asPackage ? 'Add package' : 'Add service';

    return (
        <AppLayout title={title}>
            <Button component={Link} href="/services" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                Services
            </Button>
            <PageHeader
                title={title}
                description={asPackage ? 'A bookable bundle with a price and duration. List what is included for your website.' : 'What you offer, how long it takes and what it costs.'}
            />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent>
                    <ServiceForm
                        form={form}
                        onSubmit={submit}
                        submitLabel={asPackage ? 'Add package' : 'Add service'}
                        cancelHref="/services"
                        categories={categories}
                        resources={resources}
                        packageOptions={packageOptions}
                        lockPackage={asPackage}
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

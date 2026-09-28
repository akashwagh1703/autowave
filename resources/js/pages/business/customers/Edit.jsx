import { useForm } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import CustomerForm from '@/modules/customers/CustomerForm';

export default function Edit({ customer, tags }) {
    const form = useForm({
        name: customer.name ?? '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        city: customer.city ?? '',
        address: customer.address ?? '',
        tags: customer.tags ?? [],
        notes: customer.notes ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(`/customers/${customer.id}`);
    };

    return (
        <AppLayout title={`Edit ${customer.name}`}>
            <PageHeader title="Edit customer" description={customer.name} />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent className="p-6">
                    <CustomerForm
                        form={form}
                        onSubmit={submit}
                        submitLabel="Save changes"
                        cancelHref={`/customers/${customer.id}`}
                        tagOptions={tags}
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

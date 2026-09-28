import { useForm } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import CustomerForm from '@/modules/customers/CustomerForm';

export default function Create({ tags }) {
    const form = useForm({ name: '', phone: '', email: '', city: '', address: '', tags: [], notes: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/customers');
    };

    return (
        <AppLayout title="Add customer">
            <PageHeader title="Add customer" description="One customer per phone number." />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent className="p-6">
                    <CustomerForm form={form} onSubmit={submit} submitLabel="Add customer" cancelHref="/customers" tagOptions={tags} />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

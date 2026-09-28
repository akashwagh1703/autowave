import { useForm } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import LeadForm from '@/modules/leads/LeadForm';
import useTenant from '@/hooks/useTenant';
import { toLocalInput } from '@/utils/format';

export default function Edit({ lead, sources }) {
    const { currency, timezone } = useTenant();
    const form = useForm({
        name: lead.name ?? '',
        phone: lead.phone ?? '',
        email: lead.email ?? '',
        interest: lead.interest ?? '',
        estimated_value: lead.estimated_value ?? '',
        lead_source_id: lead.lead_source_id ?? '',
        next_followup_at: toLocalInput(lead.next_followup_at, timezone),
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(`/leads/${lead.id}`);
    };

    return (
        <AppLayout title={`Edit ${lead.name}`}>
            <PageHeader title="Edit lead" description={lead.name} />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent className="p-6">
                    <LeadForm
                        form={form}
                        onSubmit={submit}
                        submitLabel="Save changes"
                        cancelHref={`/leads/${lead.id}`}
                        sources={sources}
                        currency={currency}
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

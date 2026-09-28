import { useForm } from '@inertiajs/react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import AppLayout from '@/layouts/AppLayout';
import PageHeader from '@/components/PageHeader';
import LeadForm from '@/modules/leads/LeadForm';
import useTenant from '@/hooks/useTenant';

export default function Create({ stages, sources, members, defaultSourceId }) {
    const { currency, can } = useTenant();
    const form = useForm({
        name: '',
        phone: '',
        email: '',
        interest: '',
        estimated_value: '',
        lead_source_id: defaultSourceId ?? '',
        next_followup_at: '',
        lead_stage_id: stages[0]?.id ?? '',
        assigned_tenant_user_id: '',
        notes: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => {
            const payload = { ...data };

            if (!can('leads.assign')) {
                delete payload.assigned_tenant_user_id;
            }

            return payload;
        });
        form.post('/leads');
    };

    return (
        <AppLayout title="Add lead">
            <PageHeader title="Add lead" description="A new enquiry. Matching customers are linked by phone number automatically." />
            <Card variant="outlined" className="max-w-3xl">
                <CardContent className="p-6">
                    <LeadForm
                        form={form}
                        onSubmit={submit}
                        submitLabel="Add lead"
                        cancelHref="/leads"
                        sources={sources}
                        stages={stages}
                        members={members}
                        canAssign={can('leads.assign')}
                        currency={currency}
                        creating
                    />
                </CardContent>
            </Card>
        </AppLayout>
    );
}

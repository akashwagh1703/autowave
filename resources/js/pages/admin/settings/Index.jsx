import { router } from '@inertiajs/react';
import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import FormControlLabel from '@mui/material/FormControlLabel';
import Switch from '@mui/material/Switch';
import AdminLayout from '@/layouts/AdminLayout';
import PageHeader from '@/components/PageHeader';
import BillingSettingsCard from '@/modules/billing/admin/BillingSettingsCard';

export default function SettingsIndex({ settings, unverifiedUsers, billing }) {
    const [saving, setSaving] = useState(false);
    const required = settings.require_email_verification;

    const toggle = (event) => {
        router.put(
            '/settings',
            { require_email_verification: event.target.checked },
            { preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false) },
        );
    };

    return (
        <AdminLayout title="Settings">
            <PageHeader title="Platform settings" description="These apply to every business on AutoWave." />

            <Card variant="outlined">
                <CardContent className="space-y-3">
                    <FormControlLabel
                        control={<Switch checked={required} onChange={toggle} disabled={saving} />}
                        label="Require email confirmation for new accounts"
                    />
                    <p className="text-sm text-slate-600">
                        When on, people who sign up must click the link in the confirmation email before they can create or use a
                        business. When off, new accounts are confirmed straight away and no email is sent, and anyone still waiting
                        for a confirmation email can sign in.
                    </p>
                    {!required ? (
                        <Alert severity="warning">
                            Email confirmation is off. Anyone can sign up with an email address they do not own. Turn it back on once
                            email sending works and testing is done.
                        </Alert>
                    ) : null}
                    {required && unverifiedUsers > 0 ? (
                        <Alert severity="info">
                            {unverifiedUsers} {unverifiedUsers === 1 ? 'account is' : 'accounts are'} waiting for email confirmation.
                        </Alert>
                    ) : null}
                </CardContent>
            </Card>

            <BillingSettingsCard billing={billing} />
        </AdminLayout>
    );
}

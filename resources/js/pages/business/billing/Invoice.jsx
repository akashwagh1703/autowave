import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import AppLayout from '@/layouts/AppLayout';
import InvoiceDocument from '@/modules/billing/InvoiceDocument';

export default function BillingInvoice({ invoice }) {
    return (
        <AppLayout title={`Invoice ${invoice.number}`}>
            <div className="mb-4 print:hidden">
                <Button component={Link} href="/settings/billing" size="small">
                    ← Back to billing
                </Button>
            </div>
            <InvoiceDocument invoice={invoice} />
        </AppLayout>
    );
}

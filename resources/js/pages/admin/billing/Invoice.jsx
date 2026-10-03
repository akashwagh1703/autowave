import { Link } from '@inertiajs/react';
import Button from '@mui/material/Button';
import AdminLayout from '@/layouts/AdminLayout';
import InvoiceDocument from '@/modules/billing/InvoiceDocument';

export default function AdminBillingInvoice({ invoice }) {
    return (
        <AdminLayout title={`Invoice ${invoice.number}`}>
            <div className="mb-4 print:hidden">
                <Button component={Link} href="/billing/payments?status=all" size="small">
                    ← Back to payments
                </Button>
            </div>
            <InvoiceDocument invoice={invoice} pdfUrl={`/billing/invoices/${invoice.id}/pdf`} />
        </AdminLayout>
    );
}

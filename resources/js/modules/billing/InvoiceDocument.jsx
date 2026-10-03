import Button from '@mui/material/Button';
import PrintIcon from '@mui/icons-material/Print';
import { formatDate } from '@/utils/format';
import { rupees } from '@/utils/billing';

function Party({ title, party }) {
    return (
        <div>
            <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">{title}</p>
            <p className="mt-1 font-semibold text-slate-900">{party.name}</p>
            {party.address ? <p className="text-sm whitespace-pre-line text-slate-700">{party.address}</p> : null}
            {party.email ? <p className="text-sm text-slate-700">{party.email}</p> : null}
            {party.phone ? <p className="text-sm text-slate-700">{party.phone}</p> : null}
            {party.gstin ? <p className="text-sm text-slate-700">GSTIN: {party.gstin}</p> : null}
            {party.reference ? <p className="text-sm text-slate-500">Customer ID: {party.reference}</p> : null}
        </div>
    );
}

/** An AutoWave invoice as issued (BillingPresenter::invoice); everything shown was copied in at issue time. */
export default function InvoiceDocument({ invoice }) {
    return (
        <div className="mx-auto max-w-3xl">
            <div className="mb-4 flex justify-end print:hidden">
                <Button variant="outlined" startIcon={<PrintIcon />} onClick={() => window.print()}>
                    Print or save as PDF
                </Button>
            </div>

            <article className="rounded-lg border border-slate-200 bg-white p-8 print:border-0 print:p-0">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900">{invoice.title}</h1>
                        <p className="mt-1 text-sm text-slate-600">No. {invoice.number}</p>
                    </div>
                    <div className="text-right text-sm text-slate-600">
                        <p>Date: {formatDate(invoice.issued_at, 'Asia/Kolkata')}</p>
                        {invoice.payment ? (
                            <p>
                                Paid by {invoice.payment.method_label}
                                {invoice.payment.reference ? ` · ${invoice.payment.reference}` : ''}
                            </p>
                        ) : null}
                    </div>
                </header>

                <div className="mt-6 grid gap-6 sm:grid-cols-2">
                    <Party title="From" party={invoice.seller} />
                    <Party title="Billed to" party={invoice.buyer} />
                </div>

                <table className="mt-8 w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-200 text-left text-slate-500">
                            <th className="py-2 font-medium">Description</th>
                            <th className="py-2 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.lines.map((line, index) => (
                            <tr key={index} className="border-b border-slate-100">
                                <td className="py-2 text-slate-800">{line.description}</td>
                                <td className="py-2 text-right text-slate-800">{line.amount < 0 ? `−${rupees(-line.amount)}` : rupees(line.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        {invoice.tax.length > 0 ? (
                            <>
                                <tr>
                                    <td className="pt-3 text-right text-slate-600">Taxable value</td>
                                    <td className="pt-3 text-right text-slate-800">{rupees(invoice.subtotal)}</td>
                                </tr>
                                {invoice.tax.map((line) => (
                                    <tr key={line.label}>
                                        <td className="text-right text-slate-600">
                                            {line.label} @ {line.rate}%
                                        </td>
                                        <td className="text-right text-slate-800">{rupees(line.amount)}</td>
                                    </tr>
                                ))}
                            </>
                        ) : null}
                        <tr>
                            <td className="pt-3 text-right font-semibold text-slate-900">Total</td>
                            <td className="pt-3 text-right text-lg font-bold text-slate-900">{rupees(invoice.total)}</td>
                        </tr>
                    </tfoot>
                </table>

                <p className="mt-8 text-xs text-slate-500">
                    Amount received in full. This is a computer-generated invoice and needs no signature.
                </p>
            </article>
        </div>
    );
}

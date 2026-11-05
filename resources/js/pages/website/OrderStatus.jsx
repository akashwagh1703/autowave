import { Head } from '@inertiajs/react';

export default function OrderStatus({ business, order }) {
    return (
        <div className="min-h-screen bg-slate-50 px-4 py-10 text-slate-900 antialiased">
            <Head title={`Order ${order.number}`} />
            <div className="mx-auto max-w-md space-y-6">
                <header className="space-y-1 text-center">
                    <p className="text-sm font-medium uppercase tracking-wide text-slate-500">{business.name}</p>
                    <h1 className="text-2xl font-semibold tracking-tight">Order {order.number}</h1>
                    <p className="text-base text-slate-600">{order.status_label}</p>
                </header>

                <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <dl className="space-y-3 text-sm">
                        <div className="flex justify-between gap-4">
                            <dt className="text-slate-500">Type</dt>
                            <dd className="font-medium text-right">{order.fulfilment}</dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-slate-500">Items</dt>
                            <dd className="font-medium text-right">{order.items}</dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-slate-500">Total</dt>
                            <dd className="font-medium text-right">{order.total}</dd>
                        </div>
                        {Number(order.balance) > 0 ? (
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Balance due</dt>
                                <dd className="font-medium text-right">{order.balance}</dd>
                            </div>
                        ) : null}
                        {order.customer ? (
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Customer</dt>
                                <dd className="font-medium text-right">{order.customer}</dd>
                            </div>
                        ) : null}
                        {order.delivery_address ? (
                            <div className="space-y-1">
                                <dt className="text-slate-500">Delivery</dt>
                                <dd className="font-medium whitespace-pre-wrap">{order.delivery_address}</dd>
                            </div>
                        ) : null}
                    </dl>
                </section>

                {(business.phone || business.address) && (
                    <p className="text-center text-sm text-slate-500">
                        {business.address ? <span className="block">{business.address}</span> : null}
                        {business.phone ? <span className="block">{business.phone}</span> : null}
                    </p>
                )}
            </div>
        </div>
    );
}

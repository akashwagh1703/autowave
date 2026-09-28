import { Link, router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import CallIcon from '@mui/icons-material/Call';
import ChatIcon from '@mui/icons-material/Chat';
import EditIcon from '@mui/icons-material/Edit';
import EmailIcon from '@mui/icons-material/Email';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import ConfirmDialog from '@/components/ConfirmDialog';
import AppointmentStatusChip from '@/modules/booking/AppointmentStatusChip';
import StageChip from '@/modules/leads/StageChip';
import Timeline from '@/modules/crm/Timeline';
import ActivityComposer from '@/modules/crm/ActivityComposer';
import useTenant from '@/hooks/useTenant';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';

function Detail({ label, children }) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
            <dd className="mt-1 text-sm whitespace-pre-line text-slate-900">{children || '—'}</dd>
        </div>
    );
}

export default function Show({ customer, leads, activities, activityTypes, appointments }) {
    const { timezone, currency, can, hasModule } = useTenant();
    const now = Date.now();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [processing, setProcessing] = useState(false);
    const digits = String(customer.phone ?? '').replace(/\D/g, '');
    const canSeeLeads = hasModule('leads') && can('leads.view');

    const destroy = () => {
        router.delete(`/customers/${customer.id}`, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <AppLayout title={customer.name}>
            <Button component={Link} href="/customers" startIcon={<ArrowBackIcon />} size="small" color="inherit" className="mb-3">
                All customers
            </Button>

            <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">{customer.name}</h1>
                    <div className="mt-2 flex flex-wrap gap-1">
                        {customer.tags.map((tag) => (
                            <Chip key={tag} label={tag} size="small" color="primary" variant="outlined" />
                        ))}
                        <span className="text-sm text-slate-500">Customer since {formatDate(customer.created_at, timezone)}</span>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    {appointments && can('appointments.create') ? (
                        <Button component={Link} href={`/appointments/create?customer=${customer.id}`} startIcon={<EventAvailableIcon />} variant="contained" size="small">
                            Book
                        </Button>
                    ) : null}
                    {customer.phone ? (
                        <Button href={`tel:${customer.phone}`} startIcon={<CallIcon />} variant="outlined" size="small">
                            Call
                        </Button>
                    ) : null}
                    {digits.length >= 7 ? (
                        <Button href={`https://wa.me/${digits}`} target="_blank" rel="noreferrer" startIcon={<ChatIcon />} variant="outlined" size="small">
                            WhatsApp
                        </Button>
                    ) : null}
                    {customer.email ? (
                        <Button href={`mailto:${customer.email}`} startIcon={<EmailIcon />} variant="outlined" size="small">
                            Email
                        </Button>
                    ) : null}
                    {can('customers.update') ? (
                        <Button component={Link} href={`/customers/${customer.id}/edit`} startIcon={<EditIcon />} size="small">
                            Edit
                        </Button>
                    ) : null}
                    {can('customers.delete') ? (
                        <Button color="error" size="small" onClick={() => setConfirmDelete(true)}>
                            Delete
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {can('customers.update') ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="mb-3 font-semibold text-slate-900">Log activity</h2>
                                <ActivityComposer url={`/customers/${customer.id}/activities`} types={activityTypes} timezone={timezone} />
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="mb-4 font-semibold text-slate-900">Timeline</h2>
                            <Timeline activities={activities} timezone={timezone} showLead={canSeeLeads} showAppointment={Boolean(appointments)} />
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-4">
                    <Card variant="outlined">
                        <CardContent>
                            <h2 className="font-semibold text-slate-900">Contact</h2>
                            <dl className="mt-4 grid gap-4">
                                <Detail label="Phone">{customer.phone}</Detail>
                                <Detail label="Email">{customer.email}</Detail>
                                <Detail label="City">{customer.city}</Detail>
                                <Detail label="Address">{customer.address}</Detail>
                                <Detail label="Notes">{customer.notes}</Detail>
                            </dl>
                        </CardContent>
                    </Card>

                    {appointments ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="font-semibold text-slate-900">Appointments ({appointments.length})</h2>
                                {appointments.length === 0 ? (
                                    <p className="mt-2 text-sm text-slate-600">No appointments yet.</p>
                                ) : (
                                    <ul className="mt-3 divide-y divide-slate-100">
                                        {appointments.map((appointment) => (
                                            <li key={appointment.id} className={`py-2 ${new Date(appointment.ends_at).getTime() < now ? 'opacity-80' : ''}`}>
                                                <div className="flex items-center justify-between gap-2">
                                                    <Link href={`/appointments/${appointment.id}`} className="text-sm font-medium text-slate-900 hover:text-brand-700">
                                                        {formatDateTime(appointment.starts_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                    </Link>
                                                    <AppointmentStatusChip appointment={appointment} />
                                                </div>
                                                <p className="text-xs text-slate-500">
                                                    {[appointment.service?.name, appointment.resource?.name].filter(Boolean).join(' · ')}
                                                    {appointment.price ? ` · ${formatMoney(appointment.price, currency)}` : ''}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ) : null}

                    {canSeeLeads ? (
                        <Card variant="outlined">
                            <CardContent>
                                <h2 className="font-semibold text-slate-900">Leads ({leads.length})</h2>
                                {leads.length === 0 ? (
                                    <p className="mt-2 text-sm text-slate-600">No leads linked to this customer.</p>
                                ) : (
                                    <ul className="mt-3 divide-y divide-slate-100">
                                        {leads.map((lead) => (
                                            <li key={lead.id} className="py-2">
                                                <div className="flex items-center justify-between gap-2">
                                                    <Link href={`/leads/${lead.id}`} className="text-sm font-medium text-slate-900 hover:text-brand-700">
                                                        {lead.interest ?? lead.name}
                                                    </Link>
                                                    <StageChip stage={lead.stage} />
                                                </div>
                                                <p className="text-xs text-slate-500">
                                                    {formatDateTime(lead.created_at, timezone, { dateStyle: 'medium' })}
                                                    {lead.estimated_value ? ` · ${formatMoney(lead.estimated_value, currency)}` : ''}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    ) : null}
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                title={`Delete ${customer.name}?`}
                description="The customer disappears from lists. Linked leads and their history are kept."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={destroy}
                onClose={() => setConfirmDelete(false)}
            />
        </AppLayout>
    );
}

import { Link } from '@inertiajs/react';
import AddCircleOutlineIcon from '@mui/icons-material/AddCircleOutlineOutlined';
import CallIcon from '@mui/icons-material/Call';
import EditNoteIcon from '@mui/icons-material/EditNote';
import EmailIcon from '@mui/icons-material/Email';
import EventIcon from '@mui/icons-material/Event';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import EventBusyIcon from '@mui/icons-material/EventBusy';
import EventRepeatIcon from '@mui/icons-material/EventRepeat';
import AssignmentIcon from '@mui/icons-material/AssignmentOutlined';
import TaskAltIcon from '@mui/icons-material/TaskAlt';
import HowToRegIcon from '@mui/icons-material/HowToReg';
import InstagramIcon from '@mui/icons-material/Instagram';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import PersonAddAltIcon from '@mui/icons-material/PersonAddAlt';
import RedoIcon from '@mui/icons-material/Redo';
import StickyNote2Icon from '@mui/icons-material/StickyNote2';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import ThumbDownOffAltIcon from '@mui/icons-material/ThumbDownOffAlt';
import EmptyState from '@/components/EmptyState';
import LanguageIcon from '@mui/icons-material/Language';
import LocalShippingIcon from '@mui/icons-material/LocalShippingOutlined';
import PaymentsIcon from '@mui/icons-material/PaymentsOutlined';
import RemoveShoppingCartIcon from '@mui/icons-material/RemoveShoppingCartOutlined';
import SchoolIcon from '@mui/icons-material/SchoolOutlined';
import ShoppingBagIcon from '@mui/icons-material/ShoppingBagOutlined';
import TableRestaurantIcon from '@mui/icons-material/TableRestaurantOutlined';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatPrice, formatRelative, humanize } from '@/utils/format';

const icons = {
    created: AddCircleOutlineIcon,
    note: StickyNote2Icon,
    call: CallIcon,
    whatsapp: WhatsAppIcon,
    instagram: InstagramIcon,
    email: EmailIcon,
    meeting: EventIcon,
    stage_changed: SwapHorizIcon,
    converted: HowToRegIcon,
    lost: ThumbDownOffAltIcon,
    reactivated: RedoIcon,
    assigned: PersonAddAltIcon,
    updated: EditNoteIcon,
    appointment_booked: EventAvailableIcon,
    appointment_rescheduled: EventRepeatIcon,
    appointment_confirmed: EventAvailableIcon,
    appointment_completed: TaskAltIcon,
    appointment_cancelled: EventBusyIcon,
    appointment_no_show: EventBusyIcon,
    appointment_updated: EditNoteIcon,
    task: AssignmentIcon,
    website_enquiry: LanguageIcon,
    order_placed: ShoppingBagIcon,
    order_confirmed: ShoppingBagIcon,
    order_ready: LocalShippingIcon,
    order_completed: TaskAltIcon,
    order_cancelled: RemoveShoppingCartIcon,
    payment_recorded: PaymentsIcon,
    payment_removed: PaymentsIcon,
    order_items_added: ShoppingBagIcon,
    admitted: SchoolIcon,
    enrolment_active: SchoolIcon,
    enrolment_completed: TaskAltIcon,
    enrolment_dropped: ThumbDownOffAltIcon,
    fee_paid: PaymentsIcon,
    fee_payment_removed: PaymentsIcon,
    demo_scheduled: EventIcon,
    demo_attended: EventAvailableIcon,
    demo_no_show: EventBusyIcon,
    demo_cancelled: EventBusyIcon,
    reservation_created: TableRestaurantIcon,
    reservation_confirmed: EventAvailableIcon,
    reservation_seated: TableRestaurantIcon,
    reservation_completed: TaskAltIcon,
    reservation_cancelled: EventBusyIcon,
    reservation_no_show: EventBusyIcon,
    reservation_table_assigned: TableRestaurantIcon,
};

const enrolmentVerbs = {
    enrolment_active: 're-activated',
    enrolment_completed: 'marked as completed',
    enrolment_dropped: 'marked as dropped',
};

const demoVerbs = {
    demo_attended: 'marked as attended',
    demo_no_show: 'marked as a no-show',
    demo_cancelled: 'cancelled',
};

const reservationVerbs = {
    reservation_confirmed: 'confirmed',
    reservation_seated: 'seated',
    reservation_completed: 'completed',
    reservation_cancelled: 'cancelled',
    reservation_no_show: 'marked as a no-show',
};

function enrolmentLabel(meta) {
    return [meta.course, meta.batch].filter(Boolean).join(' · ') || 'the course';
}

function demoLabel(meta, timezone) {
    const when = meta.scheduled_at ? formatDateTime(meta.scheduled_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : null;

    return `the demo class${meta.course ? ` for ${meta.course}` : ''}${when ? ` on ${when}` : ''}`;
}

function reservationLabel(meta, timezone) {
    const when = meta.reserved_at ? formatDateTime(meta.reserved_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : null;

    return `the table for ${meta.party_size ?? '?'}${when ? ` on ${when}` : ''}${meta.table ? ` (${meta.table})` : ''}`;
}

const orderVerbs = {
    order_confirmed: 'confirmed',
    order_ready: 'marked ready',
    order_completed: 'completed',
    order_cancelled: 'cancelled',
};

function orderLabel(meta) {
    return meta.number ? `order #${meta.number}` : 'the order';
}

const sent = { whatsapp: 'sent a WhatsApp message', instagram: 'sent an Instagram message', email: 'sent an email' };
const received = { whatsapp: 'sent a WhatsApp message', instagram: 'sent an Instagram message' };

function actorName(activity) {
    if (activity.user) {
        return activity.user.name;
    }

    if (activity.metadata?.direction === 'inbound') {
        return activity.metadata.from_name ?? 'The contact';
    }

    if (activity.metadata?.via === 'ai') {
        return 'AI';
    }

    if (activity.metadata?.via === 'assistant') {
        return 'WhatsApp assistant';
    }

    return activity.metadata?.via === 'automation' ? 'Automation' : 'System';
}

const aiSuffix = { ai: ' (filled in by AI)', ai_suggestion: ' (AI suggestion)' };

const logged = { note: 'added a note', call: 'logged a call', whatsapp: 'logged a WhatsApp message', email: 'logged an email', meeting: 'logged a meeting' };

const appointmentVerbs = {
    appointment_confirmed: 'confirmed',
    appointment_completed: 'completed',
    appointment_cancelled: 'cancelled',
    appointment_no_show: 'marked as a no-show',
};

function appointmentLabel(meta, timezone) {
    if (!meta.starts_at) {
        return 'the appointment';
    }

    const when = formatDateTime(meta.starts_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

    return `the appointment on ${when}${meta.resource ? ` with ${meta.resource}` : ''}${meta.service ? ` (${meta.service})` : ''}`;
}

function describe(activity, timezone, currency) {
    const meta = activity.metadata ?? {};

    if (appointmentVerbs[activity.type]) {
        return `${appointmentVerbs[activity.type]} ${appointmentLabel(meta, timezone)}`;
    }

    if (orderVerbs[activity.type]) {
        return `${orderVerbs[activity.type]} ${orderLabel(meta)}`;
    }

    if (enrolmentVerbs[activity.type]) {
        return `${enrolmentVerbs[activity.type]} in ${enrolmentLabel(meta)}`;
    }

    if (demoVerbs[activity.type]) {
        return `${demoVerbs[activity.type]} ${demoLabel(meta, timezone)}`;
    }

    if (reservationVerbs[activity.type]) {
        return `${reservationVerbs[activity.type]} ${reservationLabel(meta, timezone)}`;
    }

    switch (activity.type) {
        case 'admitted':
            return `admitted the student to ${enrolmentLabel(meta)}`;
        case 'fee_paid':
            return `recorded a fee payment of ${formatPrice(meta.amount, currency)}${meta.method_label ? ` (${meta.method_label})` : ''} for ${enrolmentLabel(meta)}`;
        case 'fee_payment_removed':
            return `removed a fee payment of ${formatPrice(meta.amount, currency)} from ${enrolmentLabel(meta)}`;
        case 'demo_scheduled':
            return `scheduled ${demoLabel(meta, timezone)}`;
        case 'reservation_created':
            return `reserved ${reservationLabel(meta, timezone)}${meta.source === 'website' ? ' online' : ''}`;
        case 'reservation_table_assigned':
            return `updated ${reservationLabel(meta, timezone)}`;
        case 'order_items_added':
            return `added ${meta.added || 'items'} to ${orderLabel(meta)}`;
        case 'order_placed':
            return `placed ${orderLabel(meta)}${meta.source === 'website' ? ' on the website' : ''}${meta.items ? `: ${meta.items}` : ''}`;
        case 'payment_recorded':
            return `recorded a payment of ${formatPrice(meta.amount, currency)}${meta.method_label ? ` (${meta.method_label})` : ''} for ${activity.appointment_id ? appointmentLabel(meta, timezone) : orderLabel(meta)}`;
        case 'payment_removed':
            return `removed a payment of ${formatPrice(meta.amount, currency)} from ${activity.appointment_id ? appointmentLabel(meta, timezone) : orderLabel(meta)}`;
        case 'created':
            if (activity.lead_id) {
                return `created the lead${meta.source ? ` from ${meta.source}` : ''}`;
            }

            if (meta.via === 'booking') {
                return 'added the customer while booking an appointment';
            }

            if (meta.via === 'online_booking') {
                return 'added the customer from an online booking';
            }

            if (meta.via === 'order') {
                return 'added the customer while creating an order';
            }

            if (meta.via === 'online_order') {
                return 'added the customer from a website order';
            }

            if (meta.via === 'admission') {
                return 'added the customer while admitting a student';
            }

            if (meta.via === 'reservation') {
                return 'added the customer while booking a table';
            }

            if (meta.via === 'online_reservation') {
                return 'added the customer from an online table booking';
            }

            return meta.lead_name ? `added the customer when converting ${meta.lead_name}` : 'added the customer';
        case 'appointment_booked':
            return `booked ${appointmentLabel(meta, timezone)}${meta.source === 'website' ? ' online' : ''}`;
        case 'website_enquiry':
            return `sent an enquiry from the website${meta.interest ? ` about ${meta.interest}` : ''}`;
        case 'appointment_rescheduled':
            return `moved ${appointmentLabel(meta.from ?? {}, timezone)} to ${formatDateTime(meta.to?.starts_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}${meta.to?.resource && meta.to.resource !== meta.from?.resource ? ` with ${meta.to.resource}` : ''}`;
        case 'appointment_updated':
            return `updated ${(meta.changed ?? []).map((field) => humanize(field).toLowerCase()).join(', ') || 'details'} of ${appointmentLabel(meta, timezone)}`;
        case 'stage_changed':
            return `moved the lead from ${meta.from?.name} to ${meta.to?.name}`;
        case 'converted':
            return `converted the lead${meta.customer ? ` to ${meta.customer.created ? 'new' : 'existing'} customer ${meta.customer.name}` : ''}`;
        case 'lost':
            return 'marked the lead as lost';
        case 'reactivated':
            return `reactivated the lead (${meta.to?.name})`;
        case 'assigned':
            if (!meta.to) {
                return 'unassigned the lead';
            }

            return `${meta.automatic ? 'auto-assigned' : 'assigned'} the lead to ${meta.to.name}`;
        case 'updated':
            return `updated ${(meta.changed ?? []).map((field) => humanize(field).toLowerCase()).join(', ') || 'details'}${aiSuffix[meta.via] ?? ''}`;
        case 'note':
            return meta.via === 'ai' ? `added a summary note${meta.automation_name ? ` (${meta.automation_name})` : ''}` : logged.note;
        case 'task':
            return `added a follow-up task${meta.due_at ? ` due ${formatDateTime(meta.due_at, timezone, { weekday: 'short', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}` : ''}${meta.automation_name ? ` (${meta.automation_name})` : ''}`;
        case 'whatsapp':
        case 'instagram':
        case 'email':
            if (meta.direction === 'inbound') {
                return `${received[activity.type] ?? 'sent a message'}${meta.opt_out === 'out' ? ' — opted out' : meta.opt_out === 'in' ? ' — opted back in' : ''}`;
            }

            if (meta.message_id) {
                const detail = meta.automation_name ?? (meta.template ? `template ${meta.template}` : null);

                return `${sent[activity.type]}${detail ? ` (${detail})` : ''}${meta.simulated ? ' — simulated, not delivered' : ''}`;
            }

            return logged[activity.type] ?? humanize(activity.type);
        default:
            return logged[activity.type] ?? humanize(activity.type);
    }
}

export default function Timeline({ activities, timezone, showLead = false, showAppointment = false, showOrder = false }) {
    const { currency } = useTenant();

    if (!activities.length) {
        return <EmptyState title="No activity yet" description="Calls, notes and changes will appear here." />;
    }

    return (
        <ol className="relative space-y-5 border-l border-slate-200 pl-6">
            {activities.map((activity) => {
                const Icon = icons[activity.type] ?? StickyNote2Icon;

                return (
                    <li key={activity.id} className="relative">
                        <span className="absolute -left-[37px] flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-brand-600">
                            <Icon sx={{ fontSize: 16 }} />
                        </span>
                        <p className="text-sm text-slate-900">
                            <span className="font-medium">{actorName(activity)}</span> {describe(activity, timezone, currency)}
                            {showOrder && activity.order_id ? (
                                <>
                                    {' · '}
                                    <Link href={`/orders/${activity.order_id}`} className="text-brand-700 hover:underline">
                                        View
                                    </Link>
                                </>
                            ) : null}
                            {showAppointment && activity.appointment_id ? (
                                <>
                                    {' · '}
                                    <Link href={`/appointments/${activity.appointment_id}`} className="text-brand-700 hover:underline">
                                        View
                                    </Link>
                                </>
                            ) : null}
                            {showLead && activity.lead ? (
                                <>
                                    {' · '}
                                    {activity.lead.deleted ? (
                                        <span className="text-slate-500">{activity.lead.name} (deleted lead)</span>
                                    ) : (
                                        <Link href={`/leads/${activity.lead.id}`} className="text-brand-700 hover:underline">
                                            {activity.lead.name}
                                        </Link>
                                    )}
                                </>
                            ) : null}
                        </p>
                        {activity.body ? (
                            <p className="mt-1 rounded-lg bg-slate-50 px-3 py-2 text-sm whitespace-pre-line text-slate-700">{activity.body}</p>
                        ) : null}
                        <p className="mt-1 text-xs text-slate-500" title={formatDateTime(activity.occurred_at, timezone)}>
                            {formatRelative(activity.occurred_at)} · {formatDateTime(activity.occurred_at, timezone)}
                        </p>
                    </li>
                );
            })}
        </ol>
    );
}

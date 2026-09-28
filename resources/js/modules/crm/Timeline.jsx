import { Link } from '@inertiajs/react';
import AddCircleOutlineIcon from '@mui/icons-material/AddCircleOutlineOutlined';
import CallIcon from '@mui/icons-material/Call';
import ChatIcon from '@mui/icons-material/Chat';
import EditNoteIcon from '@mui/icons-material/EditNote';
import EmailIcon from '@mui/icons-material/Email';
import EventIcon from '@mui/icons-material/Event';
import EventAvailableIcon from '@mui/icons-material/EventAvailable';
import EventBusyIcon from '@mui/icons-material/EventBusy';
import EventRepeatIcon from '@mui/icons-material/EventRepeat';
import TaskAltIcon from '@mui/icons-material/TaskAlt';
import HowToRegIcon from '@mui/icons-material/HowToReg';
import PersonAddAltIcon from '@mui/icons-material/PersonAddAlt';
import RedoIcon from '@mui/icons-material/Redo';
import StickyNote2Icon from '@mui/icons-material/StickyNote2';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import ThumbDownOffAltIcon from '@mui/icons-material/ThumbDownOffAlt';
import EmptyState from '@/components/EmptyState';
import { formatDateTime, formatRelative, humanize } from '@/utils/format';

const icons = {
    created: AddCircleOutlineIcon,
    note: StickyNote2Icon,
    call: CallIcon,
    whatsapp: ChatIcon,
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
};

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

function describe(activity, timezone) {
    const meta = activity.metadata ?? {};

    if (appointmentVerbs[activity.type]) {
        return `${appointmentVerbs[activity.type]} ${appointmentLabel(meta, timezone)}`;
    }

    switch (activity.type) {
        case 'created':
            if (activity.lead_id) {
                return `created the lead${meta.source ? ` from ${meta.source}` : ''}`;
            }

            if (meta.via === 'booking') {
                return 'added the customer while booking an appointment';
            }

            return meta.lead_name ? `added the customer when converting ${meta.lead_name}` : 'added the customer';
        case 'appointment_booked':
            return `booked ${appointmentLabel(meta, timezone)}`;
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
            return `updated ${(meta.changed ?? []).map((field) => humanize(field).toLowerCase()).join(', ') || 'details'}`;
        default:
            return logged[activity.type] ?? humanize(activity.type);
    }
}

export default function Timeline({ activities, timezone, showLead = false, showAppointment = false }) {
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
                            <span className="font-medium">{activity.user?.name ?? 'System'}</span> {describe(activity, timezone)}
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

import AccessTimeIcon from '@mui/icons-material/AccessTime';
import DoneIcon from '@mui/icons-material/Done';
import DoneAllIcon from '@mui/icons-material/DoneAll';
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutlineOutlined';
import ScheduleSendIcon from '@mui/icons-material/ScheduleSend';
import Tooltip from '@mui/material/Tooltip';
import { formatDateTime } from '@/utils/format';

/** WhatsApp-style ticks for an outbound message: queued, sent, delivered, read, failed. */
export default function MessageStatus({ message, timezone }) {
    const size = { fontSize: 14 };

    if (message.status === 'failed') {
        return (
            <Tooltip title={message.error ?? 'Not delivered'}>
                <span className="inline-flex items-center gap-0.5 text-red-600">
                    <ErrorOutlineIcon sx={size} /> Failed
                </span>
            </Tooltip>
        );
    }

    if (message.scheduled_for) {
        return (
            <Tooltip title={`Waits for the end of quiet hours: ${formatDateTime(message.scheduled_for, timezone)}`}>
                <ScheduleSendIcon sx={size} aria-label="Scheduled" />
            </Tooltip>
        );
    }

    const icon = {
        queued: <AccessTimeIcon sx={size} aria-label="Queued" />,
        sending: <AccessTimeIcon sx={size} aria-label="Sending" />,
        sent: <DoneIcon sx={size} aria-label="Sent" />,
        delivered: <DoneAllIcon sx={size} aria-label="Delivered" />,
        read: <DoneAllIcon sx={size} className="text-sky-500" aria-label="Read" />,
    }[message.status];

    return icon ? <Tooltip title={message.status_label ?? ''}>{icon}</Tooltip> : null;
}

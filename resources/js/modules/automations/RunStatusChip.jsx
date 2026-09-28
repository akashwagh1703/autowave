import Chip from '@mui/material/Chip';

const colors = {
    pending: 'default',
    running: 'info',
    waiting: 'warning',
    completed: 'success',
    skipped: 'default',
    failed: 'error',
    cancelled: 'default',
    queued: 'info',
    sending: 'info',
    sent: 'success',
    not_started: 'default',
};

const labels = { not_started: 'Not started' };

export default function RunStatusChip({ status, label, size = 'small' }) {
    return (
        <Chip
            size={size}
            variant="outlined"
            color={colors[status] ?? 'default'}
            label={label ?? labels[status] ?? status.charAt(0).toUpperCase() + status.slice(1)}
        />
    );
}

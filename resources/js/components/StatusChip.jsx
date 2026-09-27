import Chip from '@mui/material/Chip';

const colors = {
    active: 'success',
    pending: 'warning',
    invited: 'info',
    draft: 'default',
    suspended: 'error',
    disabled: 'default',
    failed: 'error',
    deprecated: 'default',
};

export default function StatusChip({ status, size = 'small' }) {
    return (
        <Chip
            label={status.charAt(0).toUpperCase() + status.slice(1)}
            color={colors[status] ?? 'default'}
            size={size}
            variant="outlined"
        />
    );
}

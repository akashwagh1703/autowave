import Chip from '@mui/material/Chip';

const COLORS = { active: 'primary', completed: 'success', dropped: 'default' };

export default function EnrolmentStatusChip({ status, label }) {
    return <Chip label={label} size="small" color={COLORS[status] ?? 'default'} variant="outlined" />;
}

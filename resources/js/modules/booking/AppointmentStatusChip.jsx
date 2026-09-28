import Chip from '@mui/material/Chip';
import { STATUS_STYLES } from '@/utils/booking';

export default function AppointmentStatusChip({ appointment, size = 'small' }) {
    return (
        <Chip
            label={appointment.status_label}
            color={STATUS_STYLES[appointment.status]?.chip ?? 'default'}
            variant={appointment.status === 'confirmed' ? 'filled' : 'outlined'}
            size={size}
        />
    );
}

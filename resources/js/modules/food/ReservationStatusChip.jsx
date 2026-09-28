import Chip from '@mui/material/Chip';

const COLORS = { pending: 'warning', confirmed: 'primary', seated: 'secondary', completed: 'success', cancelled: 'default', no_show: 'error' };

export default function ReservationStatusChip({ reservation }) {
    return <Chip label={reservation.status_label} size="small" color={COLORS[reservation.status] ?? 'default'} variant={reservation.status === 'seated' ? 'filled' : 'outlined'} />;
}

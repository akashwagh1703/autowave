import Chip from '@mui/material/Chip';

const STATUS_COLORS = {
    pending: 'warning',
    confirmed: 'primary',
    ready: 'info',
    completed: 'success',
    cancelled: 'default',
};

const PAYMENT_COLORS = {
    unpaid: 'error',
    partial: 'warning',
    paid: 'success',
};

export function OrderStatusChip({ order, size = 'small' }) {
    return <Chip size={size} variant="outlined" color={STATUS_COLORS[order.status] ?? 'default'} label={order.status_label} />;
}

export function PaymentChip({ order, size = 'small' }) {
    if (order.status === 'cancelled' && order.payment_status !== 'paid') {
        return null;
    }

    return <Chip size={size} variant="outlined" color={PAYMENT_COLORS[order.payment_status] ?? 'default'} label={order.payment_status_label} />;
}

import IconButton from '@mui/material/IconButton';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import useTenant from '@/hooks/useTenant';
import { formatDateTime, formatPrice } from '@/utils/format';

/** Manually recorded payments, each removable (for mistakes) when `onRemove` is given. */
export default function PaymentsList({ payments, onRemove = null }) {
    const { currency, timezone } = useTenant();

    if (payments.length === 0) {
        return <p className="mt-2 text-sm text-slate-500">No payments recorded.</p>;
    }

    return (
        <ul className="mt-2 divide-y divide-slate-100">
            {payments.map((payment) => (
                <li key={payment.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                    <div>
                        <p className="text-slate-900">
                            {formatPrice(payment.amount, currency)} · {payment.method_label}
                            {payment.reference ? <span className="text-slate-500"> · {payment.reference}</span> : null}
                        </p>
                        <p className="text-xs text-slate-500">
                            {formatDateTime(payment.paid_at, timezone)}
                            {payment.recorded_by ? ` · recorded by ${payment.recorded_by}` : ''}
                        </p>
                    </div>
                    {onRemove ? (
                        <IconButton size="small" aria-label="Remove payment" onClick={() => onRemove(payment)}>
                            <DeleteOutlineIcon fontSize="small" />
                        </IconButton>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

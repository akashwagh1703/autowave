import { router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import { useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';

const LABELS = { confirmed: 'Confirm', seated: 'Seat', completed: 'Complete', no_show: 'No-show', cancelled: 'Cancel' };

/** One button per allowed status change; cancelling asks for an optional reason. */
export default function ReservationActions({ reservation, size = 'small' }) {
    const [processing, setProcessing] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');

    const change = (status, extra = {}) =>
        router.patch(`/reservations/${reservation.id}/status`, { status, ...extra }, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setCancelling(false);
                setReason('');
            },
        });

    return (
        <span className="flex flex-wrap justify-end gap-1">
            {reservation.transitions.map((transition) => (
                <Button
                    key={transition.value}
                    size={size}
                    disabled={processing}
                    color={transition.value === 'cancelled' ? 'error' : transition.value === 'no_show' ? 'inherit' : 'primary'}
                    variant={transition.value === 'seated' || transition.value === 'confirmed' ? 'outlined' : 'text'}
                    onClick={() => (transition.value === 'cancelled' ? setCancelling(true) : change(transition.value))}
                >
                    {LABELS[transition.value] ?? transition.label}
                </Button>
            ))}
            <ConfirmDialog
                open={cancelling}
                title="Cancel this reservation?"
                description="The table becomes free for other guests."
                confirmLabel="Cancel reservation"
                destructive
                processing={processing}
                onConfirm={() => change('cancelled', { reason })}
                onClose={() => setCancelling(false)}
            >
                <TextField
                    fullWidth
                    size="small"
                    label="Reason (optional)"
                    value={reason}
                    onChange={(event) => setReason(event.target.value)}
                    slotProps={{ htmlInput: { maxLength: 255 } }}
                    className="mt-3"
                />
            </ConfirmDialog>
        </span>
    );
}

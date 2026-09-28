import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import useTenant from '@/hooks/useTenant';
import { formatPrice, toLocalInput } from '@/utils/format';

/** Records a manual payment (cash, UPI, card…) against `action`, capped at `balance`. */
export default function PaymentDialog({ action, balance, methods, open, onClose, title = 'Record payment' }) {
    const { currency, timezone } = useTenant();
    const form = useForm({ amount: balance, method: methods[0]?.value ?? 'cash', reference: '', paid_at: toLocalInput(new Date().toISOString(), timezone) });

    const submit = (event) => {
        event.preventDefault();
        form.post(action, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>{title}</DialogTitle>
                <DialogContent className="space-y-4">
                    <p className="text-sm text-slate-600">Balance due: {formatPrice(balance, currency)}</p>
                    <TextField
                        label="Amount"
                        type="number"
                        required
                        fullWidth
                        autoFocus
                        value={form.data.amount}
                        onChange={(event) => form.setData('amount', event.target.value)}
                        error={Boolean(form.errors.amount)}
                        helperText={form.errors.amount}
                        slotProps={{ htmlInput: { min: 0.01, max: balance, step: '0.01' }, input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> } }}
                    />
                    <TextField
                        select
                        label="Method"
                        fullWidth
                        value={form.data.method}
                        onChange={(event) => form.setData('method', event.target.value)}
                        error={Boolean(form.errors.method)}
                        helperText={form.errors.method}
                    >
                        {methods.map((method) => (
                            <MenuItem key={method.value} value={method.value}>
                                {method.label}
                            </MenuItem>
                        ))}
                    </TextField>
                    <TextField
                        label="Reference"
                        fullWidth
                        value={form.data.reference}
                        onChange={(event) => form.setData('reference', event.target.value)}
                        error={Boolean(form.errors.reference)}
                        helperText={form.errors.reference ?? 'Optional, e.g. UPI transaction ID.'}
                        slotProps={{ htmlInput: { maxLength: 100 } }}
                    />
                    <TextField
                        label="Received"
                        type="datetime-local"
                        fullWidth
                        value={form.data.paid_at}
                        onChange={(event) => form.setData('paid_at', event.target.value)}
                        error={Boolean(form.errors.paid_at)}
                        helperText={form.errors.paid_at}
                        slotProps={{ inputLabel: { shrink: true } }}
                    />
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        Record payment
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

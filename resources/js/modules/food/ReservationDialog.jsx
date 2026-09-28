import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import TextField from '@mui/material/TextField';
import { useState } from 'react';
import CustomerPicker from '@/modules/booking/CustomerPicker';
import useTenant from '@/hooks/useTenant';
import { formatDuration } from '@/utils/booking';
import { toLocalInput } from '@/utils/format';

/** Books a table (no `reservation`) or changes an existing reservation's time, party and table. */
export default function ReservationDialog({ reservation = null, date, tables, durationOptions, defaultDuration, open, onClose }) {
    const { timezone } = useTenant();
    const [mode, setMode] = useState('existing');
    const [picked, setPicked] = useState(null);
    const form = useForm({
        reserved_at: reservation ? toLocalInput(reservation.reserved_at, timezone) : `${date}T19:00`,
        duration_minutes: reservation?.duration_minutes ?? defaultDuration,
        party_size: reservation?.party_size ?? 2,
        dining_table_id: reservation?.dining_table_id ?? '',
        notes: reservation?.notes ?? '',
        customer: { name: '', phone: '' },
        seated: false,
    });

    const durations = durationOptions.includes(Number(form.data.duration_minutes)) ? durationOptions : [...durationOptions, Number(form.data.duration_minutes)].sort((a, b) => a - b);

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (reservation) {
            form.transform((data) => ({
                reserved_at: data.reserved_at,
                duration_minutes: data.duration_minutes,
                party_size: data.party_size,
                dining_table_id: data.dining_table_id || null,
                notes: data.notes,
            }));
            form.put(`/reservations/${reservation.id}`, options);

            return;
        }

        form.transform((data) => ({
            reserved_at: data.reserved_at,
            duration_minutes: data.duration_minutes,
            party_size: data.party_size,
            dining_table_id: data.dining_table_id || null,
            notes: data.notes,
            seated: data.seated,
            customer_id: mode === 'existing' ? picked?.id ?? null : null,
            customer: mode === 'new' ? data.customer : null,
        }));
        form.post('/reservations', options);
    };

    const tooSmall = (table) => table.seats < Number(form.data.party_size);

    return (
        <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
            <form onSubmit={submit} noValidate>
                <DialogTitle>{reservation ? 'Change reservation' : 'Book a table'}</DialogTitle>
                <DialogContent className="space-y-4">
                    {!reservation ? (
                        <>
                            <Tabs value={mode} onChange={(_, value) => setMode(value)}>
                                <Tab value="existing" label="Existing customer" />
                                <Tab value="new" label="New guest" />
                            </Tabs>
                            {mode === 'existing' ? (
                                <CustomerPicker value={picked} onChange={setPicked} endpoint="/reservations/customers" label="Guest" error={form.errors.customer_id ?? form.errors['customer.name']} />
                            ) : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField
                                        label="Name"
                                        required
                                        value={form.data.customer.name}
                                        onChange={(event) => form.setData('customer', { ...form.data.customer, name: event.target.value })}
                                        error={Boolean(form.errors['customer.name'] || form.errors.customer_id)}
                                        helperText={form.errors['customer.name'] ?? form.errors.customer_id}
                                        slotProps={{ htmlInput: { maxLength: 120 } }}
                                    />
                                    <TextField
                                        label="Phone"
                                        value={form.data.customer.phone}
                                        onChange={(event) => form.setData('customer', { ...form.data.customer, phone: event.target.value })}
                                        error={Boolean(form.errors['customer.phone'])}
                                        helperText={form.errors['customer.phone'] ?? 'Reuses an existing customer with this phone.'}
                                        slotProps={{ htmlInput: { maxLength: 30 } }}
                                    />
                                </div>
                            )}
                        </>
                    ) : null}
                    <div className="grid gap-4 sm:grid-cols-3">
                        <TextField
                            label="Date and time"
                            type="datetime-local"
                            required
                            value={form.data.reserved_at}
                            onChange={(event) => form.setData('reserved_at', event.target.value)}
                            error={Boolean(form.errors.reserved_at)}
                            helperText={form.errors.reserved_at}
                            slotProps={{ inputLabel: { shrink: true } }}
                            className="sm:col-span-2"
                        />
                        <TextField
                            label="Guests"
                            type="number"
                            required
                            value={form.data.party_size}
                            onChange={(event) => form.setData('party_size', event.target.value)}
                            error={Boolean(form.errors.party_size)}
                            helperText={form.errors.party_size}
                            slotProps={{ htmlInput: { min: 1, max: 100 } }}
                        />
                        <TextField
                            select
                            label="Duration"
                            value={form.data.duration_minutes}
                            onChange={(event) => form.setData('duration_minutes', event.target.value)}
                            error={Boolean(form.errors.duration_minutes)}
                            helperText={form.errors.duration_minutes}
                        >
                            {durations.map((minutes) => (
                                <MenuItem key={minutes} value={minutes}>
                                    {formatDuration(minutes)}
                                </MenuItem>
                            ))}
                        </TextField>
                        <TextField
                            select
                            label="Table"
                            value={form.data.dining_table_id}
                            onChange={(event) => form.setData('dining_table_id', event.target.value)}
                            error={Boolean(form.errors.dining_table_id)}
                            helperText={form.errors.dining_table_id ?? 'Optional; assign later.'}
                            slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}
                            className="sm:col-span-2"
                        >
                            <MenuItem value="">
                                <em>Not assigned</em>
                            </MenuItem>
                            {tables.map((table) => (
                                <MenuItem key={table.id} value={table.id}>
                                    {table.name}
                                    <span className="ml-2 text-xs text-slate-500">
                                        {table.seats} seats{table.area ? ` · ${table.area}` : ''}
                                        {tooSmall(table) ? ' · too small' : ''}
                                    </span>
                                </MenuItem>
                            ))}
                        </TextField>
                    </div>
                    <TextField
                        label="Notes"
                        fullWidth
                        value={form.data.notes ?? ''}
                        onChange={(event) => form.setData('notes', event.target.value)}
                        error={Boolean(form.errors.notes)}
                        helperText={form.errors.notes ?? 'e.g. birthday, high chair, window seat'}
                        slotProps={{ htmlInput: { maxLength: 1000 } }}
                    />
                    {!reservation ? (
                        <FormControlLabel
                            control={<Checkbox checked={form.data.seated} onChange={(event) => form.setData('seated', event.target.checked)} />}
                            label="Walk-in: seat them now"
                        />
                    ) : null}
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose} color="inherit">
                        Cancel
                    </Button>
                    <Button type="submit" variant="contained" disabled={form.processing}>
                        {reservation ? 'Save changes' : 'Book table'}
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

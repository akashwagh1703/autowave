import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import { toLocalInput } from '@/utils/format';

/**
 * Log a note, call, message or meeting. On a lead, the next follow-up can be changed in the
 * same step (it is only sent when edited).
 */
export default function ActivityComposer({ url, types, timezone, followUp }) {
    const initialFollowUp = followUp !== undefined ? toLocalInput(followUp, timezone) : null;
    const form = useForm({ type: 'note', body: '', next_followup_at: initialFollowUp ?? '' });

    const followUpChanged = initialFollowUp !== null && form.data.next_followup_at !== initialFollowUp;

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            type: data.type,
            body: data.body,
            ...(followUpChanged ? { update_followup: true, next_followup_at: data.next_followup_at || null } : {}),
        }));
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => form.setData((data) => ({ ...data, body: '' })),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <ToggleButtonGroup
                size="small"
                exclusive
                value={form.data.type}
                onChange={(_, value) => value && form.setData('type', value)}
                aria-label="Activity type"
                className="flex-wrap"
            >
                {types.map((type) => (
                    <ToggleButton key={type.value} value={type.value} className="normal-case">
                        {type.label}
                    </ToggleButton>
                ))}
            </ToggleButtonGroup>

            <TextField
                fullWidth
                multiline
                minRows={2}
                label={form.data.type === 'note' ? 'Note' : 'What happened? (optional)'}
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                error={Boolean(form.errors.body || form.errors.type)}
                helperText={form.errors.body ?? form.errors.type}
                slotProps={{ htmlInput: { maxLength: 5000 } }}
            />

            <div className="flex flex-wrap items-start gap-3">
                {initialFollowUp !== null ? (
                    <TextField
                        size="small"
                        type="datetime-local"
                        label="Next follow-up"
                        value={form.data.next_followup_at}
                        onChange={(event) => form.setData('next_followup_at', event.target.value)}
                        error={Boolean(form.errors.next_followup_at)}
                        helperText={form.errors.next_followup_at ?? (followUpChanged ? 'Will be updated' : 'Leave as is or change')}
                        slotProps={{ inputLabel: { shrink: true } }}
                    />
                ) : null}
                <Button type="submit" variant="contained" disabled={form.processing} className="ml-auto">
                    Log activity
                </Button>
            </div>
        </form>
    );
}

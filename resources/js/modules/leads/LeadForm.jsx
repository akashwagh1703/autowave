import Button from '@mui/material/Button';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { Link } from '@inertiajs/react';

/**
 * Lead details. `creating` adds stage, assignee and first note, which are separate actions on
 * an existing lead.
 */
export default function LeadForm({ form, onSubmit, submitLabel, cancelHref, sources, stages = [], members = [], canAssign = false, creating = false, currency }) {
    const field = (name, label, props = {}) => (
        <TextField
            label={label}
            fullWidth
            value={form.data[name] ?? ''}
            onChange={(event) => form.setData(name, event.target.value)}
            error={Boolean(form.errors[name])}
            helperText={form.errors[name] ?? props.helperText}
            {...props}
        />
    );

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-6">
            <section className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">{field('name', 'Name', { required: true, autoFocus: creating, slotProps: { htmlInput: { maxLength: 150 } } })}</div>
                {field('phone', 'Phone', { type: 'tel', placeholder: '+91 98765 43210', helperText: 'Phone or email is required.' })}
                {field('email', 'Email', { type: 'email' })}
                <div className="sm:col-span-2">
                    {field('interest', 'Interested in', { placeholder: 'e.g. Bridal package, membership, demo class', slotProps: { htmlInput: { maxLength: 255 } } })}
                </div>
                {field('estimated_value', 'Estimated value', {
                    type: 'number',
                    slotProps: {
                        htmlInput: { min: 0, step: '0.01' },
                        input: { startAdornment: <InputAdornment position="start">{currency}</InputAdornment> },
                    },
                })}
                {field('lead_source_id', 'Source', {
                    select: true,
                    children: [
                        <MenuItem key="none" value="">
                            <em>Not set</em>
                        </MenuItem>,
                        ...sources.map((source) => (
                            <MenuItem key={source.id} value={source.id}>
                                {source.name}
                            </MenuItem>
                        )),
                    ],
                })}
                {field('next_followup_at', 'Next follow-up', { type: 'datetime-local', slotProps: { inputLabel: { shrink: true } } })}
                {creating
                    ? field('lead_stage_id', 'Stage', {
                          select: true,
                          children: stages.map((stage) => (
                              <MenuItem key={stage.id} value={stage.id}>
                                  {stage.name}
                              </MenuItem>
                          )),
                      })
                    : null}
                {creating && canAssign
                    ? field('assigned_tenant_user_id', 'Assign to', {
                          select: true,
                          helperText: 'Leave empty to use your assignment settings.',
                          children: [
                              <MenuItem key="none" value="">
                                  <em>Unassigned</em>
                              </MenuItem>,
                              ...members.map((member) => (
                                  <MenuItem key={member.id} value={member.id}>
                                      {member.name}
                                  </MenuItem>
                              )),
                          ],
                      })
                    : null}
                {creating ? (
                    <div className="sm:col-span-2">
                        {field('notes', 'First note', { multiline: true, minRows: 3, slotProps: { htmlInput: { maxLength: 5000 } } })}
                    </div>
                ) : null}
            </section>

            <div className="flex justify-end gap-2">
                <Button component={Link} href={cancelHref} color="inherit">
                    Cancel
                </Button>
                <Button type="submit" variant="contained" disabled={form.processing}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}

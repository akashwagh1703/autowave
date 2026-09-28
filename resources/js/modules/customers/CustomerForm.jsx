import { Link } from '@inertiajs/react';
import Autocomplete from '@mui/material/Autocomplete';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import TextField from '@mui/material/TextField';

const MAX_TAGS = 10;

export default function CustomerForm({ form, onSubmit, submitLabel, cancelHref, tagOptions = [] }) {
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

    const tagError = form.errors.tags ?? Object.entries(form.errors).find(([key]) => key.startsWith('tags.'))?.[1];

    return (
        <form onSubmit={onSubmit} noValidate className="space-y-6">
            <section className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">{field('name', 'Name', { required: true, autoFocus: true, slotProps: { htmlInput: { maxLength: 150 } } })}</div>
                {field('phone', 'Phone', { type: 'tel', placeholder: '+91 98765 43210', helperText: 'Used to match leads to this customer.' })}
                {field('email', 'Email', { type: 'email' })}
                {field('city', 'City', { slotProps: { htmlInput: { maxLength: 80 } } })}
                {field('address', 'Address', { slotProps: { htmlInput: { maxLength: 255 } } })}
                <div className="sm:col-span-2">
                    <Autocomplete
                        multiple
                        freeSolo
                        options={tagOptions}
                        value={form.data.tags}
                        onChange={(_, value) => form.setData('tags', value.map((tag) => tag.trim()).filter(Boolean).slice(0, MAX_TAGS))}
                        renderValue={(value, getItemProps) =>
                            value.map((tag, index) => {
                                const { key, ...itemProps } = getItemProps({ index });

                                return <Chip key={key} label={tag} size="small" {...itemProps} />;
                            })
                        }
                        renderInput={(params) => (
                            <TextField
                                {...params}
                                label="Tags"
                                error={Boolean(tagError)}
                                helperText={tagError ?? 'Press Enter to add a tag, e.g. VIP or Regular.'}
                            />
                        )}
                    />
                </div>
                <div className="sm:col-span-2">
                    {field('notes', 'Notes', { multiline: true, minRows: 3, slotProps: { htmlInput: { maxLength: 5000 } } })}
                </div>
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

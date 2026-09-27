import Button from '@mui/material/Button';
import CircularProgress from '@mui/material/CircularProgress';
import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import StepHeading from './StepHeading';

function slugHelper(status, error, onUseSuggestion) {
    if (error) {
        return error;
    }

    switch (status.state) {
        case 'checking':
            return 'Checking availability…';
        case 'available':
            return `Your website will be live at ${status.domain}`;
        case 'taken':
        case 'invalid':
            return (
                <span>
                    {status.state === 'taken'
                        ? 'This address is taken.'
                        : 'Use at least 3 lowercase letters, numbers or hyphens.'}
                    {status.suggestion ? (
                        <>
                            {' '}
                            <Button size="small" variant="text" onClick={() => onUseSuggestion(status.suggestion)} className="min-w-0 p-0 align-baseline normal-case">
                                Use {status.suggestion}
                            </Button>
                        </>
                    ) : null}
                </span>
            );
        case 'error':
            return 'Could not check availability. We will check again when you continue.';
        default:
            return 'Lowercase letters, numbers and hyphens. This becomes your free web address.';
    }
}

export default function DetailsStep({ form, slugStatus, domainSuffix, onNameChange, onSlugChange }) {
    const field = (name, label, props = {}) => (
        <TextField
            label={label}
            fullWidth
            value={form.data[name]}
            onChange={(event) => form.setData(name, event.target.value)}
            error={Boolean(form.errors[name])}
            helperText={form.errors[name] ?? props.helperText}
            {...props}
        />
    );

    const slugError = form.errors.slug ?? null;
    const slugBad = Boolean(slugError) || ['taken', 'invalid'].includes(slugStatus.state);

    return (
        <div>
            <StepHeading title="Tell us about your business" description="Customers will see these details on your website." />

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    {field('name', 'Business name', {
                        required: true,
                        autoFocus: true,
                        autoComplete: 'organization',
                        onChange: (event) => onNameChange(event.target.value),
                    })}
                </div>

                <div className="sm:col-span-2">
                    <TextField
                        label="Web address"
                        required
                        fullWidth
                        value={form.data.slug}
                        onChange={(event) => onSlugChange(event.target.value)}
                        error={slugBad}
                        helperText={slugHelper(slugStatus, slugError, onSlugChange)}
                        slotProps={{
                            htmlInput: { autoCapitalize: 'none', spellCheck: false, maxLength: 63 },
                            input: {
                                endAdornment: (
                                    <InputAdornment position="end">
                                        {slugStatus.state === 'checking' ? <CircularProgress size={16} className="mr-2" /> : null}
                                        <span className="text-sm text-slate-500">{domainSuffix}</span>
                                    </InputAdornment>
                                ),
                            },
                        }}
                    />
                </div>

                {field('phone', 'Phone', { required: true, type: 'tel', autoComplete: 'tel', placeholder: '+91 98765 43210' })}
                {field('email', 'Business email', { type: 'email', autoComplete: 'email' })}
                {field('city', 'City', { required: true, autoComplete: 'address-level2' })}
                {field('address', 'Address', { autoComplete: 'street-address' })}

                <div className="sm:col-span-2">
                    {field('description', 'Short description', {
                        multiline: true,
                        minRows: 3,
                        helperText: `${form.data.description.length}/500 · Shown in the "About" section of your website.`,
                        slotProps: { htmlInput: { maxLength: 500 } },
                    })}
                </div>
            </div>
        </div>
    );
}

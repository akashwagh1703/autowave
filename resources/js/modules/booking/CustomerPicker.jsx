import Autocomplete from '@mui/material/Autocomplete';
import CircularProgress from '@mui/material/CircularProgress';
import TextField from '@mui/material/TextField';
import { useEffect, useRef, useState } from 'react';
import { getJson } from '@/utils/booking';

/** Search existing customers by name, phone or email. */
export default function CustomerPicker({ value, onChange, error, autoFocus = false }) {
    const [input, setInput] = useState('');
    const [options, setOptions] = useState([]);
    const [loading, setLoading] = useState(false);
    const timer = useRef(null);

    useEffect(() => {
        clearTimeout(timer.current);

        if (input.trim().length < 2) {
            setOptions(value ? [value] : []);
            setLoading(false);

            return undefined;
        }

        let cancelled = false;
        setLoading(true);
        timer.current = setTimeout(() => {
            getJson('/appointments/customers', { search: input.trim() })
                .then((data) => !cancelled && setOptions(data.data))
                .catch(() => !cancelled && setOptions([]))
                .finally(() => !cancelled && setLoading(false));
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer.current);
        };
    }, [input, value]);

    return (
        <Autocomplete
            value={value}
            onChange={(_, next) => onChange(next)}
            inputValue={input}
            onInputChange={(_, next) => setInput(next)}
            options={options}
            filterOptions={(list) => list}
            getOptionLabel={(option) => option.name}
            isOptionEqualToValue={(option, selected) => option.id === selected.id}
            loading={loading}
            noOptionsText={input.trim().length < 2 ? 'Type at least 2 characters' : 'No matching customers'}
            renderOption={(props, option) => {
                const { key, ...optionProps } = props;

                return (
                    <li key={key} {...optionProps}>
                        <div>
                            <p className="text-sm text-slate-900">{option.name}</p>
                            <p className="text-xs text-slate-500">{[option.phone, option.email].filter(Boolean).join(' · ') || '—'}</p>
                        </div>
                    </li>
                );
            }}
            renderInput={(params) => (
                <TextField
                    {...params}
                    label="Customer"
                    autoFocus={autoFocus}
                    placeholder="Search name, phone or email"
                    error={Boolean(error)}
                    helperText={error}
                    slotProps={{
                        ...params.slotProps,
                        input: {
                            ...params.slotProps?.input,
                            endAdornment: (
                                <>
                                    {loading ? <CircularProgress size={16} /> : null}
                                    {params.slotProps?.input?.endAdornment}
                                </>
                            ),
                        },
                    }}
                />
            )}
        />
    );
}

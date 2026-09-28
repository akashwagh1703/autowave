import InputAdornment from '@mui/material/InputAdornment';
import TextField from '@mui/material/TextField';
import CircularProgress from '@mui/material/CircularProgress';
import SearchIcon from '@mui/icons-material/Search';

export default function SearchField({ value, onChange, placeholder = 'Search', loading = false, className }) {
    return (
        <TextField
            size="small"
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value)}
            placeholder={placeholder}
            className={className}
            slotProps={{
                htmlInput: { 'aria-label': placeholder, maxLength: 100 },
                input: {
                    startAdornment: (
                        <InputAdornment position="start">
                            <SearchIcon fontSize="small" />
                        </InputAdornment>
                    ),
                    endAdornment: loading ? (
                        <InputAdornment position="end">
                            <CircularProgress size={16} />
                        </InputAdornment>
                    ) : null,
                },
            }}
        />
    );
}

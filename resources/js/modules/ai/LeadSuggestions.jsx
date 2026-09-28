import { router } from '@inertiajs/react';
import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import Tooltip from '@mui/material/Tooltip';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import useAi from '@/hooks/useAi';

const show = (value) => (value === null || value === undefined || value === '' ? '—' : String(value));

/**
 * Lead details found by AI. Empty fields were filled automatically; values that differ from what the
 * lead already has wait here until someone applies or dismisses them.
 */
export default function LeadSuggestions({ leadId, ai: data }) {
    const ai = useAi();
    const pending = data?.suggestions;
    const [selected, setSelected] = useState(() => pending?.suggestions.map((suggestion) => suggestion.attribute) ?? []);
    const [busy, setBusy] = useState(false);

    if (!ai.enabled || !data) {
        return null;
    }

    const post = (url, payload = {}) =>
        router.post(url, payload, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    const toggle = (attribute) =>
        setSelected((current) => (current.includes(attribute) ? current.filter((item) => item !== attribute) : [...current, attribute]));

    const extract = (
        <span>
            <Button size="small" startIcon={<AutoAwesomeIcon fontSize="small" />} disabled={busy || !ai.available} onClick={() => post(`/ai/leads/${leadId}/extract`)}>
                Fill details from messages
            </Button>
        </span>
    );

    return (
        <div className="space-y-2">
            {data.can_extract ? <div>{ai.available ? extract : <Tooltip title={ai.message ?? ''}>{extract}</Tooltip>}</div> : null}

            {pending && pending.suggestions.length > 0 ? (
                <Alert severity="info" icon={<AutoAwesomeIcon fontSize="inherit" />}>
                    <p className="font-medium">AI found details that differ from this lead</p>
                    {pending.summary ? <p className="mt-1 text-sm">{pending.summary}</p> : null}
                    {pending.preferred_time ? <p className="mt-1 text-sm">Preferred time: {pending.preferred_time}</p> : null}
                    <div className="mt-2 flex flex-col">
                        {pending.suggestions.map((suggestion) => (
                            <FormControlLabel
                                key={suggestion.attribute}
                                control={
                                    <Checkbox
                                        size="small"
                                        checked={selected.includes(suggestion.attribute)}
                                        disabled={!data.can_extract}
                                        onChange={() => toggle(suggestion.attribute)}
                                    />
                                }
                                label={
                                    <span className="text-sm">
                                        {suggestion.label}: <span className="text-slate-500 line-through">{show(suggestion.current)}</span> →{' '}
                                        <span className="font-medium">{show(suggestion.value)}</span>
                                    </span>
                                }
                            />
                        ))}
                    </div>
                    {data.can_extract ? (
                        <div className="mt-2 flex gap-2">
                            <Button
                                size="small"
                                variant="contained"
                                disabled={busy || selected.length === 0}
                                onClick={() => post(`/ai/leads/${leadId}/suggestions/apply`, { result_id: pending.id, attributes: selected })}
                            >
                                Apply selected
                            </Button>
                            <Button
                                size="small"
                                color="inherit"
                                disabled={busy}
                                onClick={() =>
                                    post(`/ai/leads/${leadId}/suggestions/dismiss`, {
                                        result_id: pending.id,
                                        attributes: pending.suggestions.map((suggestion) => suggestion.attribute),
                                    })
                                }
                            >
                                Dismiss
                            </Button>
                        </div>
                    ) : null}
                </Alert>
            ) : null}
        </div>
    );
}

import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Tooltip from '@mui/material/Tooltip';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import useAi from '@/hooks/useAi';
import { errorMessage, postJson } from '@/utils/http';

/**
 * An AI summary of a lead, customer or conversation. Made on request (it costs tokens) and reused until
 * something new happens; "Refresh" makes a new one.
 */
export default function SummaryCard({ url, initial = null, title = 'AI summary', variant = 'card' }) {
    const ai = useAi();
    const [summary, setSummary] = useState(initial);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    if (!ai.enabled) {
        return null;
    }

    const load = async (refresh) => {
        setLoading(true);
        setError(null);

        try {
            const data = await postJson(url, { refresh });
            setSummary(data.summary);
        } catch (failure) {
            setError(errorMessage(failure, 'AI could not summarise this right now.'));
        } finally {
            setLoading(false);
        }
    };

    const action = (
        <span>
            <Button size="small" startIcon={<AutoAwesomeIcon fontSize="small" />} onClick={() => load(Boolean(summary))} disabled={loading || !ai.available}>
                {loading ? 'Summarising…' : summary ? 'Refresh' : 'Summarise'}
            </Button>
        </span>
    );

    const body = (
        <>
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold text-slate-900">{title}</h2>
                {ai.available ? action : <Tooltip title={ai.message ?? ''}>{action}</Tooltip>}
            </div>
            {error ? (
                <Alert severity="error" className="mt-2">
                    {error}
                </Alert>
            ) : null}
            {summary ? (
                <>
                    <p className="mt-2 text-sm whitespace-pre-line text-slate-700">{summary.text}</p>
                    {summary.updated_at ? (
                        <p className="mt-2 text-xs text-slate-400">
                            Made {new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(summary.updated_at))} · AI can
                            make mistakes
                        </p>
                    ) : null}
                </>
            ) : (
                <p className="mt-2 text-sm text-slate-500">Get a short summary of the history so far.</p>
            )}
        </>
    );

    if (variant === 'plain') {
        return <div>{body}</div>;
    }

    return (
        <Card variant="outlined">
            <CardContent>{body}</CardContent>
        </Card>
    );
}

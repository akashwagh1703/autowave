import { useState } from 'react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import useAi from '@/hooks/useAi';
import { errorMessage, postJson } from '@/utils/http';

/**
 * "Write with AI": asks for a draft of a text field and lets the user edit it before using it. The
 * text is only used when the user presses "Use this text"; nothing is saved from here.
 *
 * `kind` is a config/ai.php copy kind; `context` describes the field (section, field, current text…).
 */
export function WriteWithAiDialog({ open, onClose, onUse, kind, context = {}, title = 'Write with AI', placeholder }) {
    const [instructions, setInstructions] = useState('');
    const [text, setText] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const generate = async () => {
        setLoading(true);
        setError(null);

        try {
            const data = await postJson('/ai/write', { kind, context, instructions: instructions.trim() || null });
            setText(data.text ?? '');
        } catch (failure) {
            setError(errorMessage(failure, 'AI could not write this right now. Please try again.'));
        } finally {
            setLoading(false);
        }
    };

    const close = () => {
        setError(null);
        onClose();
    };

    return (
        <Dialog open={open} onClose={close} fullWidth maxWidth="sm">
            <DialogTitle>{title}</DialogTitle>
            <DialogContent className="space-y-3">
                <TextField
                    label="What should it say? (optional)"
                    fullWidth
                    multiline
                    minRows={2}
                    value={instructions}
                    onChange={(event) => setInstructions(event.target.value)}
                    placeholder={placeholder ?? 'e.g. mention the weekend offer, keep it short'}
                    slotProps={{ htmlInput: { maxLength: 500 } }}
                    sx={{ mt: 1 }}
                />
                <div className="flex justify-end">
                    <Button onClick={generate} disabled={loading} startIcon={<AutoAwesomeIcon />} variant="outlined">
                        {loading ? 'Writing…' : text ? 'Try again' : 'Write it'}
                    </Button>
                </div>
                {error ? <Alert severity="error">{error}</Alert> : null}
                {text ? (
                    <TextField
                        label="Suggestion (edit freely)"
                        fullWidth
                        multiline
                        minRows={4}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        helperText="AI can make mistakes. Check prices, dates and names before using it."
                    />
                ) : null}
            </DialogContent>
            <DialogActions>
                <Button onClick={close} color="inherit">
                    Cancel
                </Button>
                <Button
                    variant="contained"
                    disabled={!text.trim()}
                    onClick={() => {
                        onUse(text.trim());
                        close();
                    }}
                >
                    Use this text
                </Button>
            </DialogActions>
        </Dialog>
    );
}

/** A small "Write with AI" button that opens the dialog. Hidden when the user cannot use AI. */
export default function WriteWithAi({ kind, context, onUse, label = 'Write with AI', title, size = 'small', placeholder }) {
    const ai = useAi();
    const [open, setOpen] = useState(false);

    if (!ai.enabled) {
        return null;
    }

    const button = (
        <span>
            <Button size={size} startIcon={<AutoAwesomeIcon fontSize="small" />} onClick={() => setOpen(true)} disabled={!ai.available}>
                {label}
            </Button>
        </span>
    );

    return (
        <>
            {ai.available ? button : <Tooltip title={ai.message ?? ''}>{button}</Tooltip>}
            {open ? (
                <WriteWithAiDialog
                    open={open}
                    onClose={() => setOpen(false)}
                    onUse={onUse}
                    kind={kind}
                    context={typeof context === 'function' ? context() : context}
                    title={title}
                    placeholder={placeholder}
                />
            ) : null}
        </>
    );
}

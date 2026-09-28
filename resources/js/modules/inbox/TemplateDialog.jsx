import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import MenuItem from '@mui/material/MenuItem';
import TextField from '@mui/material/TextField';
import { useMemo } from 'react';
import { uuid } from '@/modules/inbox/channels';

function preview(body, params) {
    return String(body ?? '').replace(/\{\{\s*(\d+)\s*\}\}/g, (match, index) => params[Number(index) - 1] || match);
}

/** Pick an approved WhatsApp template, fill its variables, send it. */
export default function TemplateDialog({ open, onClose, conversation, templates }) {
    const form = useForm({ template_id: templates[0]?.id ?? '', params: [], client_id: uuid() });
    const template = useMemo(() => templates.find((item) => item.id === form.data.template_id) ?? null, [templates, form.data.template_id]);
    const params = Array.from({ length: template?.variables ?? 0 }, (_, index) => form.data.params[index] ?? '');

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, params }));
        form.post(`/inbox/${conversation.id}/template`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.setData('client_id', uuid());
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
            <form onSubmit={submit} noValidate>
                <DialogTitle>Send a template</DialogTitle>
                <DialogContent className="space-y-4">
                    <p className="text-sm text-slate-600">Templates are approved by Meta and can be sent at any time, even after the 24-hour window has closed.</p>
                    <TextField
                        select
                        fullWidth
                        size="small"
                        label="Template"
                        value={form.data.template_id}
                        onChange={(event) => form.setData({ ...form.data, template_id: event.target.value, params: [] })}
                        error={Boolean(form.errors.template || form.errors.template_id)}
                        helperText={form.errors.template ?? form.errors.template_id}
                        sx={{ mt: 1 }}
                    >
                        {templates.map((item) => (
                            <MenuItem key={item.id} value={item.id}>
                                {item.name} · {item.language}
                                {item.category ? <span className="ml-2 text-xs text-slate-500">{item.category.toLowerCase()}</span> : null}
                            </MenuItem>
                        ))}
                    </TextField>
                    {params.map((value, index) => (
                        <TextField
                            key={index}
                            fullWidth
                            size="small"
                            label={`Variable {{${index + 1}}}`}
                            value={value}
                            onChange={(event) => {
                                const next = [...params];
                                next[index] = event.target.value;
                                form.setData('params', next);
                            }}
                            slotProps={{ htmlInput: { maxLength: 500 } }}
                        />
                    ))}
                    {form.errors.params ? <p className="text-sm text-red-600">{form.errors.params}</p> : null}
                    {template ? (
                        <div>
                            <p className="text-xs font-medium tracking-wide text-slate-500 uppercase">Preview</p>
                            <p className="mt-1 rounded-lg bg-emerald-50 px-3 py-2 text-sm whitespace-pre-line text-slate-800">{preview(template.body, params) || '(no body text)'}</p>
                        </div>
                    ) : null}
                </DialogContent>
                <DialogActions>
                    <Button onClick={onClose}>Cancel</Button>
                    <Button type="submit" variant="contained" disabled={form.processing || !template}>
                        Send template
                    </Button>
                </DialogActions>
            </form>
        </Dialog>
    );
}

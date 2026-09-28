import { router, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import TextField from '@mui/material/TextField';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlined';
import UploadIcon from '@mui/icons-material/Upload';
import { useRef, useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';

function AltText({ media, disabled }) {
    const [value, setValue] = useState(media.alt ?? '');
    const save = () => {
        if ((media.alt ?? '') !== value) {
            router.patch(`/website/media/${media.id}`, { alt: value }, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <TextField
            size="small"
            fullWidth
            label="Description"
            placeholder="What is in the photo?"
            value={value}
            disabled={disabled}
            onChange={(event) => setValue(event.target.value)}
            onBlur={save}
            slotProps={{ htmlInput: { maxLength: 150 } }}
        />
    );
}

/**
 * Upload and manage the images of one media collection (logo, hero image or gallery). Rules
 * (size, types, count) come from the server; it checks them again on upload.
 */
export default function MediaManager({ rules, items, canManage }) {
    const input = useRef(null);
    const form = useForm({ collection: rules.collection, file: null });
    const [removing, setRemoving] = useState(null);
    const single = rules.max === 1;
    const full = !single && items.length >= rules.max;

    const upload = (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        form.transform((data) => ({ ...data, file }));
        form.post('/website/media', { forceFormData: true, preserveScroll: true });
    };

    const move = (index, offset) => {
        const ids = items.map((item) => item.id);
        [ids[index], ids[index + offset]] = [ids[index + offset], ids[index]];
        router.put('/website/media/order', { collection: rules.collection, ids }, { preserveScroll: true });
    };

    const remove = () => {
        router.delete(`/website/media/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) });
    };

    return (
        <div className="space-y-3">
            {items.length ? (
                <div className={single ? 'max-w-xs' : 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3'}>
                    {items.map((media, index) => (
                        <div key={media.id} className="space-y-2 rounded-lg border border-slate-200 p-2">
                            <div className="flex aspect-video items-center justify-center overflow-hidden rounded-md bg-slate-100">
                                <img src={media.url} alt={media.alt ?? ''} className="max-h-full max-w-full object-contain" />
                            </div>
                            <AltText key={`${media.id}-${media.alt}`} media={media} disabled={!canManage} />
                            <div className="flex items-center justify-between">
                                <span className="truncate text-xs text-slate-500" title={media.original_name}>
                                    {media.width}×{media.height}
                                </span>
                                {canManage ? (
                                    <div className="flex">
                                        {!single ? (
                                            <>
                                                <IconButton size="small" aria-label="Move earlier" disabled={index === 0} onClick={() => move(index, -1)}>
                                                    <ArrowBackIcon fontSize="small" />
                                                </IconButton>
                                                <IconButton size="small" aria-label="Move later" disabled={index === items.length - 1} onClick={() => move(index, 1)}>
                                                    <ArrowForwardIcon fontSize="small" />
                                                </IconButton>
                                            </>
                                        ) : null}
                                        <IconButton size="small" aria-label="Remove image" onClick={() => setRemoving(media)}>
                                            <DeleteOutlineIcon fontSize="small" />
                                        </IconButton>
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="text-sm text-slate-600">No image yet.</p>
            )}

            {canManage ? (
                <div>
                    <input ref={input} type="file" accept={rules.accept} className="hidden" onChange={upload} aria-label={`Upload ${rules.label}`} />
                    <Button variant="outlined" startIcon={<UploadIcon />} onClick={() => input.current?.click()} disabled={form.processing || full}>
                        {form.processing ? 'Uploading…' : single && items.length ? 'Replace image' : 'Upload image'}
                    </Button>
                    <p className="mt-1 text-xs text-slate-500">
                        JPG, PNG or WebP, up to {Math.round((rules.max_kb / 1024) * 10) / 10} MB, at least {rules.min_dimension} px wide.
                        {!single ? ` Up to ${rules.max} images.` : ''}
                    </p>
                    {form.errors.file || form.errors.collection || form.errors.throttle ? (
                        <p className="mt-1 text-sm text-red-600" role="alert">
                            {form.errors.file || form.errors.collection || form.errors.throttle}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <ConfirmDialog
                open={Boolean(removing)}
                title="Remove this image?"
                description="It is deleted from your website straight away."
                confirmLabel="Remove"
                destructive
                onConfirm={remove}
                onClose={() => setRemoving(null)}
            />
        </div>
    );
}

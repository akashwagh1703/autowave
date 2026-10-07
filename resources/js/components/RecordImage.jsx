import { router, usePage } from '@inertiajs/react';
import Button from '@mui/material/Button';
import ImageIcon from '@mui/icons-material/ImageOutlined';
import { useRef, useState } from 'react';

/** The single photo of a product, service or course: preview, upload/replace and remove (POST/DELETE `endpoint`). */
export default function RecordImage({ image, alt, endpoint, title = 'Image', help, className = 'aspect-square w-full' }) {
    const { errors } = usePage().props;
    const input = useRef(null);
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    const upload = (file) => {
        if (file) {
            router.post(endpoint, { image: file }, { ...options, forceFormData: true });
        }
    };

    return (
        <div>
            <h2 className="font-semibold text-slate-900">{title}</h2>
            <div className={`mt-3 flex items-center justify-center overflow-hidden rounded-lg bg-slate-100 ${className}`}>
                {image ? <img src={image.url} alt={alt} className="h-full w-full object-cover" /> : <ImageIcon className="text-slate-400" sx={{ fontSize: 48 }} />}
            </div>
            {errors.image ? <p className="mt-2 text-sm text-red-600">{errors.image}</p> : null}
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(event) => {
                    upload(event.target.files?.[0]);
                    event.target.value = '';
                }}
                aria-label={`Upload ${title.toLowerCase()}`}
            />
            <div className="mt-3 flex gap-2">
                <Button variant="outlined" size="small" disabled={busy} onClick={() => input.current?.click()}>
                    {image ? 'Replace' : 'Upload'}
                </Button>
                {image ? (
                    <Button size="small" color="error" disabled={busy} onClick={() => router.delete(endpoint, options)}>
                        Remove
                    </Button>
                ) : null}
            </div>
            {help ? <p className="mt-2 text-xs text-slate-500">{help}</p> : null}
        </div>
    );
}

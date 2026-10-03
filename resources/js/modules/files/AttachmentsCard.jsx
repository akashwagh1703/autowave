import { router, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import IconButton from '@mui/material/IconButton';
import LinearProgress from '@mui/material/LinearProgress';
import TextField from '@mui/material/TextField';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlined';
import DownloadIcon from '@mui/icons-material/Download';
import InsertDriveFileIcon from '@mui/icons-material/InsertDriveFileOutlined';
import UploadIcon from '@mui/icons-material/Upload';
import { useRef, useState } from 'react';
import ConfirmDialog from '@/components/ConfirmDialog';
import { formatBytes, formatDate } from '@/utils/format';

/**
 * Files attached to a record (FilesPresenter::card). The server decides the allowed types, sizes and
 * permissions and checks them again on upload; files open through AutoWave, never a storage link.
 */
export default function AttachmentsCard({ documents, timezone, title = 'Documents' }) {
    const input = useRef(null);
    const form = useForm({ file: null, title: '' });
    const [tooLarge, setTooLarge] = useState(false);
    const [removing, setRemoving] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const { items, storage } = documents;
    const full = items.length >= documents.max_files;

    const choose = (event) => {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (!file) {
            return;
        }

        form.clearErrors();
        setTooLarge(file.size > documents.max_bytes);
        form.setData({ file, title: '' });
    };

    const cancel = () => {
        form.reset();
        form.clearErrors();
        setTooLarge(false);
    };

    const upload = (event) => {
        event.preventDefault();
        form.post(documents.upload_url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const remove = () => {
        router.delete(`/attachments/${removing.id}`, {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onFinish: () => {
                setDeleting(false);
                setRemoving(null);
            },
        });
    };

    const error = tooLarge ? `This file is ${formatBytes(form.data.file?.size)}. The limit is ${formatBytes(documents.max_bytes)}.` : form.errors.file || form.errors.title;

    return (
        <Card variant="outlined">
            <CardContent>
                <h2 className="font-semibold text-slate-900">
                    {title} ({items.length})
                </h2>

                {items.length === 0 ? (
                    <p className="mt-2 text-sm text-slate-600">No files yet.</p>
                ) : (
                    <ul className="mt-3 divide-y divide-slate-100">
                        {items.map((item) => (
                            <li key={item.id} className="flex items-center gap-2 py-2">
                                <InsertDriveFileIcon fontSize="small" className="shrink-0 text-slate-400" />
                                <div className="min-w-0 flex-1">
                                    <a href={item.url} target="_blank" rel="noopener" className="block truncate text-sm font-medium text-slate-900 hover:text-brand-700" title={item.original_name}>
                                        {item.name}
                                    </a>
                                    <p className="truncate text-xs text-slate-500">
                                        {[item.extension, formatBytes(item.size_bytes), formatDate(item.created_at, timezone), item.uploaded_by].filter(Boolean).join(' · ')}
                                    </p>
                                </div>
                                <IconButton size="small" component="a" href={item.download_url} aria-label={`Download ${item.name}`}>
                                    <DownloadIcon fontSize="small" />
                                </IconButton>
                                {documents.can_manage ? (
                                    <IconButton size="small" aria-label={`Delete ${item.name}`} onClick={() => setRemoving(item)}>
                                        <DeleteOutlineIcon fontSize="small" />
                                    </IconButton>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}

                {documents.can_manage ? (
                    <div className="mt-3">
                        <input ref={input} type="file" accept={documents.accept} className="hidden" onChange={choose} aria-label={`Upload to ${title}`} />
                        {form.data.file ? (
                            <form onSubmit={upload} className="space-y-2 rounded-lg border border-slate-200 p-3">
                                <p className="truncate text-sm text-slate-700" title={form.data.file.name}>
                                    {form.data.file.name} · {formatBytes(form.data.file.size)}
                                </p>
                                <TextField
                                    size="small"
                                    fullWidth
                                    label="Name (optional)"
                                    placeholder="e.g. Aadhaar card, Marksheet"
                                    value={form.data.title}
                                    disabled={form.processing}
                                    onChange={(event) => form.setData('title', event.target.value)}
                                    slotProps={{ htmlInput: { maxLength: documents.title_max } }}
                                />
                                {form.progress ? <LinearProgress variant="determinate" value={form.progress.percentage ?? 0} /> : null}
                                <div className="flex justify-end gap-2">
                                    <Button size="small" color="inherit" onClick={cancel} disabled={form.processing}>
                                        Cancel
                                    </Button>
                                    <Button size="small" type="submit" variant="contained" disabled={form.processing || tooLarge}>
                                        {form.processing ? 'Uploading…' : 'Upload'}
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <Button variant="outlined" size="small" startIcon={<UploadIcon />} onClick={() => input.current?.click()} disabled={full}>
                                Upload file
                            </Button>
                        )}
                        <p className="mt-1 text-xs text-slate-500">
                            {documents.hint}. {full ? `This record already has ${documents.max_files} files.` : ''}
                        </p>
                        {error ? (
                            <p className="mt-1 text-sm text-red-600" role="alert">
                                {error}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                {storage ? (
                    <p className={`mt-3 text-xs ${storage.percent >= 90 ? 'text-amber-700' : 'text-slate-500'}`}>
                        Business storage: {formatBytes(storage.used_bytes)} of {formatBytes(storage.cap_bytes)} used
                    </p>
                ) : null}
            </CardContent>

            <ConfirmDialog
                open={Boolean(removing)}
                title={`Delete ${removing?.name ?? 'file'}?`}
                description="The file is removed for good. It cannot be recovered."
                confirmLabel="Delete"
                destructive
                processing={deleting}
                onConfirm={remove}
                onClose={() => setRemoving(null)}
            />
        </Card>
    );
}

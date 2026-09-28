import { router, useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import IconButton from '@mui/material/IconButton';
import TextField from '@mui/material/TextField';
import CheckIcon from '@mui/icons-material/Check';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlineOutlined';
import EditIcon from '@mui/icons-material/Edit';
import { useState } from 'react';

function CategoryRow({ category }) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const form = useForm({ name: category.name });

    const save = (event) => {
        event.preventDefault();
        form.put(`/service-categories/${category.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const destroy = () => router.delete(`/service-categories/${category.id}`, { preserveScroll: true });

    return (
        <li className="flex items-center gap-2 py-2">
            {editing ? (
                <form onSubmit={save} className="flex flex-1 items-center gap-2">
                    <TextField
                        size="small"
                        autoFocus
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={Boolean(form.errors.name)}
                        helperText={form.errors.name}
                        slotProps={{ htmlInput: { maxLength: 80, 'aria-label': 'Category name' } }}
                        className="flex-1"
                    />
                    <IconButton type="submit" size="small" aria-label="Save category" disabled={form.processing}>
                        <CheckIcon fontSize="small" />
                    </IconButton>
                </form>
            ) : (
                <>
                    <span className="flex-1 text-sm text-slate-900">{category.name}</span>
                    <span className="text-xs text-slate-500">
                        {category.services_count} {category.services_count === 1 ? 'service' : 'services'}
                    </span>
                    <IconButton size="small" aria-label={`Rename ${category.name}`} onClick={() => setEditing(true)}>
                        <EditIcon fontSize="small" />
                    </IconButton>
                    {confirming ? (
                        <Button size="small" color="error" onClick={destroy}>
                            Confirm delete
                        </Button>
                    ) : (
                        <IconButton size="small" aria-label={`Delete ${category.name}`} onClick={() => setConfirming(true)}>
                            <DeleteOutlineIcon fontSize="small" />
                        </IconButton>
                    )}
                </>
            )}
        </li>
    );
}

export default function CategoryManager({ open, onClose, categories }) {
    const form = useForm({ name: '' });

    const add = (event) => {
        event.preventDefault();
        form.post('/service-categories', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
            <DialogTitle>Service categories</DialogTitle>
            <DialogContent>
                <p className="text-sm text-slate-600">Group services on your price list. Deleting a category keeps its services, uncategorised.</p>
                <ul className="mt-2 divide-y divide-slate-100">
                    {categories.map((category) => (
                        <CategoryRow key={`${category.id}-${category.name}`} category={category} />
                    ))}
                </ul>
                <form onSubmit={add} className="mt-4 flex items-start gap-2">
                    <TextField
                        size="small"
                        label="New category"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                        error={Boolean(form.errors.name)}
                        helperText={form.errors.name}
                        slotProps={{ htmlInput: { maxLength: 80 } }}
                        className="flex-1"
                    />
                    <Button type="submit" variant="outlined" disabled={form.processing || !form.data.name.trim()}>
                        Add
                    </Button>
                </form>
            </DialogContent>
            <DialogActions>
                <Button onClick={onClose}>Done</Button>
            </DialogActions>
        </Dialog>
    );
}

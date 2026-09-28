import { router } from '@inertiajs/react';
import Button from '@mui/material/Button';
import { useState } from 'react';

/** Outcome buttons for a scheduled demo class. */
export default function DemoActions({ demo, size = 'small' }) {
    const [processing, setProcessing] = useState(false);
    const set = (status) =>
        router.patch(`/demos/${demo.id}`, { status }, { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) });

    return (
        <span className="flex flex-wrap justify-end gap-1">
            <Button size={size} disabled={processing} onClick={() => set('attended')}>
                Attended
            </Button>
            <Button size={size} color="inherit" disabled={processing} onClick={() => set('no_show')}>
                No-show
            </Button>
            <Button size={size} color="error" disabled={processing} onClick={() => set('cancelled')}>
                Cancel
            </Button>
        </span>
    );
}

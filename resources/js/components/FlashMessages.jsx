import { usePage } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Snackbar from '@mui/material/Snackbar';
import { useEffect, useState } from 'react';

export default function FlashMessages() {
    const { flash } = usePage().props;
    const [open, setOpen] = useState(false);
    const [message, setMessage] = useState({ severity: 'success', text: '' });

    useEffect(() => {
        if (flash?.error) {
            setMessage({ severity: 'error', text: flash.error });
            setOpen(true);
        } else if (flash?.success) {
            setMessage({ severity: 'success', text: flash.success });
            setOpen(true);
        }
    }, [flash?.error, flash?.success]);

    return (
        <Snackbar
            open={open}
            autoHideDuration={5000}
            onClose={() => setOpen(false)}
            anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
        >
            <Alert severity={message.severity} variant="filled" onClose={() => setOpen(false)}>
                {message.text}
            </Alert>
        </Snackbar>
    );
}

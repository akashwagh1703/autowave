import Button from '@mui/material/Button';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';

export default function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel = 'Confirm',
    destructive = false,
    processing = false,
    onConfirm,
    onClose,
    children,
}) {
    return (
        <Dialog open={open} onClose={processing ? undefined : onClose} maxWidth="xs" fullWidth>
            <DialogTitle>{title}</DialogTitle>
            <DialogContent>
                {description ? <DialogContentText>{description}</DialogContentText> : null}
                {children}
            </DialogContent>
            <DialogActions>
                <Button onClick={onClose} disabled={processing} color="inherit">
                    Cancel
                </Button>
                <Button
                    onClick={onConfirm}
                    disabled={processing}
                    variant="contained"
                    color={destructive ? 'error' : 'primary'}
                >
                    {confirmLabel}
                </Button>
            </DialogActions>
        </Dialog>
    );
}

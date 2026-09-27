import { router, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import AuthLayout from '@/layouts/AuthLayout';

export default function VerifyEmail({ status }) {
    const form = useForm({});

    const resend = (event) => {
        event.preventDefault();
        form.post('/email/verification-notification');
    };

    return (
        <AuthLayout
            title="Verify your email"
            subtitle="We've sent a verification link to your email address. Click it to continue."
        >
            {status === 'verification-link-sent' ? (
                <Alert severity="success" className="mb-4">
                    A new verification link has been sent.
                </Alert>
            ) : null}

            <form onSubmit={resend} className="flex flex-wrap items-center justify-between gap-3">
                <Button type="submit" variant="contained" disabled={form.processing}>
                    Resend verification email
                </Button>
                <Button type="button" color="inherit" onClick={() => router.post('/logout')}>
                    Log out
                </Button>
            </form>
        </AuthLayout>
    );
}

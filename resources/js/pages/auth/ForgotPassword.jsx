import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import AuthLayout from '@/layouts/AuthLayout';

export default function ForgotPassword({ status }) {
    const form = useForm({ email: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/forgot-password');
    };

    return (
        <AuthLayout title="Reset your password" subtitle="Enter your email and we'll send you a reset link.">
            {status ? (
                <Alert severity="success" className="mb-4">
                    {status}
                </Alert>
            ) : null}

            <form onSubmit={submit} className="space-y-4" noValidate>
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="username"
                    autoFocus
                    fullWidth
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    error={Boolean(form.errors.email)}
                    helperText={form.errors.email}
                />

                <Button type="submit" variant="contained" size="large" fullWidth disabled={form.processing}>
                    Email reset link
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-slate-600">
                <Link href="/login" className="font-medium text-brand-600 hover:text-brand-700">
                    Back to log in
                </Link>
            </p>
        </AuthLayout>
    );
}

import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import AuthLayout from '@/layouts/AuthLayout';

export default function ResetPassword({ token, email }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout title="Choose a new password">
            <form onSubmit={submit} className="space-y-4" noValidate>
                <TextField
                    label="Email"
                    type="email"
                    autoComplete="username"
                    fullWidth
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    error={Boolean(form.errors.email)}
                    helperText={form.errors.email}
                />
                <TextField
                    label="New password"
                    type="password"
                    autoComplete="new-password"
                    autoFocus
                    fullWidth
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    error={Boolean(form.errors.password)}
                    helperText={form.errors.password}
                />
                <TextField
                    label="Confirm new password"
                    type="password"
                    autoComplete="new-password"
                    fullWidth
                    value={form.data.password_confirmation}
                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                />

                <Button type="submit" variant="contained" size="large" fullWidth disabled={form.processing}>
                    Reset password
                </Button>
            </form>
        </AuthLayout>
    );
}

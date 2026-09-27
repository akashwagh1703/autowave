import { useForm } from '@inertiajs/react';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import AuthLayout from '@/layouts/AuthLayout';

export default function Login() {
    const form = useForm({ email: '', password: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title="Super Admin sign in" badge="Platform administration">
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
                <TextField
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    fullWidth
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    error={Boolean(form.errors.password)}
                    helperText={form.errors.password}
                />

                <Button type="submit" variant="contained" size="large" fullWidth disabled={form.processing}>
                    Sign in
                </Button>
            </form>
        </AuthLayout>
    );
}

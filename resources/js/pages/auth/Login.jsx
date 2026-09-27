import { Link, useForm } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import TextField from '@mui/material/TextField';
import AuthLayout from '@/layouts/AuthLayout';

export default function Login({ status, canRegister }) {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title="Log in" subtitle="Welcome back. Sign in to your business workspace.">
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

                <div className="flex items-center justify-between">
                    <FormControlLabel
                        control={
                            <Checkbox
                                checked={form.data.remember}
                                onChange={(event) => form.setData('remember', event.target.checked)}
                            />
                        }
                        label="Remember me"
                    />
                    <Link href="/forgot-password" className="text-sm font-medium text-brand-600 hover:text-brand-700">
                        Forgot password?
                    </Link>
                </div>

                <Button type="submit" variant="contained" size="large" fullWidth disabled={form.processing}>
                    Log in
                </Button>
            </form>

            {canRegister ? (
                <p className="mt-6 text-center text-sm text-slate-600">
                    New to AutoWave?{' '}
                    <Link href="/register" className="font-medium text-brand-600 hover:text-brand-700">
                        Create an account
                    </Link>
                </p>
            ) : null}
        </AuthLayout>
    );
}

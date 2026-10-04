import { Link, useForm, usePage } from '@inertiajs/react';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import AuthLayout from '@/layouts/AuthLayout';

export default function Register() {
    const { app } = usePage().props;
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    const field = (name, label, props = {}) => (
        <TextField
            label={label}
            fullWidth
            value={form.data[name]}
            onChange={(event) => form.setData(name, event.target.value)}
            error={Boolean(form.errors[name])}
            helperText={form.errors[name]}
            {...props}
        />
    );

    return (
        <AuthLayout title="Create your account" subtitle="Start with your account; you'll set up your business next.">
            <form onSubmit={submit} className="space-y-4" noValidate>
                {field('name', 'Full name', { autoComplete: 'name', autoFocus: true })}
                {field('email', 'Email', { type: 'email', autoComplete: 'username' })}
                {field('password', 'Password', { type: 'password', autoComplete: 'new-password' })}
                {field('password_confirmation', 'Confirm password', { type: 'password', autoComplete: 'new-password' })}

                <Button type="submit" variant="contained" size="large" fullWidth disabled={form.processing}>
                    Create account
                </Button>
                <p className="text-center text-xs text-slate-500">
                    By creating an account you agree to our{' '}
                    <a href={`${app.marketing_url}/terms`} target="_blank" rel="noopener" className="text-brand-600 hover:underline">
                        Terms of service
                    </a>{' '}
                    and{' '}
                    <a href={`${app.marketing_url}/privacy`} target="_blank" rel="noopener" className="text-brand-600 hover:underline">
                        Privacy policy
                    </a>
                    .
                </p>
            </form>

            <p className="mt-6 text-center text-sm text-slate-600">
                Already have an account?{' '}
                <Link href="/login" className="font-medium text-brand-600 hover:text-brand-700">
                    Log in
                </Link>
            </p>
        </AuthLayout>
    );
}

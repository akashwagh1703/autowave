import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/** Reminder for signed-in users who have not clicked the link in their verification email yet. */
export default function VerifyEmailBanner() {
    const { auth } = usePage().props;
    const [state, setState] = useState('idle');

    if (!auth?.user || auth.user.email_verified) {
        return null;
    }

    const resend = () =>
        router.post(
            '/email/verification-notification',
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setState('sending'),
                onSuccess: () => setState('sent'),
                onError: () => setState('idle'),
            },
        );

    return (
        <div className="border-b border-amber-200 bg-amber-50 px-4 py-2 text-center text-sm text-amber-900 print:hidden" role="status">
            Please confirm your email address <strong>{auth.user.email}</strong> using the link we sent you.{' '}
            {state === 'sent' ? (
                <span className="font-semibold">A new link is on its way.</span>
            ) : (
                <button type="button" onClick={resend} disabled={state === 'sending'} className="font-semibold underline disabled:opacity-60">
                    {state === 'sending' ? 'Sending…' : 'Send it again'}
                </button>
            )}
        </div>
    );
}

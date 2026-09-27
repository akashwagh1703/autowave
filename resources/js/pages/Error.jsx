import { Head } from '@inertiajs/react';

const messages = {
    403: { title: 'Access denied', description: "You don't have permission to view this page." },
    404: { title: 'Page not found', description: "The page you're looking for doesn't exist." },
    500: { title: 'Something went wrong', description: 'We hit an unexpected error. Please try again shortly.' },
    503: { title: 'Down for maintenance', description: "We're making improvements. Please check back soon." },
};

export default function ErrorPage({ status }) {
    const { title, description } = messages[status] ?? messages[500];

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 text-center">
            <Head title={title} />
            <p className="text-sm font-semibold text-brand-600">{status}</p>
            <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-900">{title}</h1>
            <p className="mt-2 max-w-md text-slate-600">{description}</p>
            <button
                type="button"
                onClick={() => window.history.back()}
                className="mt-6 text-sm font-medium text-brand-600 hover:text-brand-700"
            >
                ← Go back
            </button>
        </div>
    );
}

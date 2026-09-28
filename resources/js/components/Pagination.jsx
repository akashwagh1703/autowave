import { router } from '@inertiajs/react';
import MuiPagination from '@mui/material/Pagination';

// Server-side pagination: keeps the current query string and changes `page`.
export default function Pagination({ meta, noun = 'results' }) {
    if (!meta || meta.total === 0) {
        return null;
    }

    const goTo = (page) => {
        const params = new URLSearchParams(window.location.search);
        params.set('page', String(page));

        router.get(`${window.location.pathname}?${params.toString()}`, {}, { preserveState: true, preserveScroll: false });
    };

    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-slate-600">
                Showing {meta.from}–{meta.to} of {meta.total} {meta.total === 1 ? noun.replace(/s$/, '') : noun}
            </p>
            {meta.last_page > 1 ? (
                <MuiPagination
                    count={meta.last_page}
                    page={meta.current_page}
                    onChange={(_, page) => goTo(page)}
                    color="primary"
                    shape="rounded"
                />
            ) : null}
        </div>
    );
}

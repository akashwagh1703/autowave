import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * List filters kept in the query string. `apply` visits immediately; `applyDebounced` waits
 * (for search boxes). Empty values are dropped from the URL; the page resets to 1.
 */
export default function useFilters(url, initial, { delay = 350 } = {}) {
    const [filters, setFilters] = useState(initial);
    const [loading, setLoading] = useState(false);
    const current = useRef(initial);
    const timer = useRef(null);

    useEffect(() => () => clearTimeout(timer.current), []);

    const visit = useCallback(
        (next) => {
            const query = Object.fromEntries(
                Object.entries(next).filter(([, value]) => value !== null && value !== undefined && value !== ''),
            );

            router.get(url, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            });
        },
        [url],
    );

    const update = useCallback((changes) => {
        current.current = { ...current.current, ...changes };
        setFilters(current.current);

        return current.current;
    }, []);

    const apply = useCallback(
        (changes) => {
            clearTimeout(timer.current);
            visit(update(changes));
        },
        [visit, update],
    );

    const applyDebounced = useCallback(
        (changes) => {
            const next = update(changes);
            clearTimeout(timer.current);
            timer.current = setTimeout(() => visit(next), delay);
        },
        [visit, update, delay],
    );

    return { filters, apply, applyDebounced, loading };
}

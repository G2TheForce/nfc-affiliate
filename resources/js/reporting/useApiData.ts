import { useEffect, useState } from 'react';
import { api, ApiError } from '@/lib/api';

interface Result<T> {
    path: string;
    data: T | null;
    error: string | null;
}

/**
 * Loads `path` whenever it changes. The previous result stays on screen
 * while the next one loads, so changing the period does not blank the page.
 */
export function useApiData<T>(path: string) {
    const [result, setResult] = useState<Result<T> | null>(null);
    const [lastData, setLastData] = useState<T | null>(null);

    useEffect(() => {
        let active = true;
        api<{ data: T }>(path)
            .then((r) => {
                if (!active) return;
                setResult({ path, data: r.data, error: null });
                setLastData(r.data);
            })
            .catch((e) => {
                if (!active) return;
                const field = e instanceof ApiError ? Object.values(e.errors)[0]?.[0] : undefined;
                setResult({ path, data: null, error: field ?? (e instanceof ApiError ? e.message : 'Could not load the figures. Please try again.') });
            });
        return () => {
            active = false;
        };
    }, [path]);

    const current = result?.path === path ? result : null;
    return { data: current?.data ?? lastData, error: current?.error ?? null, loading: current === null };
}

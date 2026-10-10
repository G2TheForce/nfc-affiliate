import { useEffect, useState } from 'react';
import { api } from './api';

const EVENT = 'notifications:changed';

export const notifyUnreadChanged = () => window.dispatchEvent(new Event(EVENT));

/** Unread notification count, refreshed on navigation events and every minute. */
export function useUnreadCount(): number {
    const [count, setCount] = useState(0);

    useEffect(() => {
        let active = true;
        const load = () =>
            api<{ meta: { unread: number } }>('/notifications')
                .then((r) => active && setCount(r.meta.unread))
                .catch(() => undefined);
        load();
        const timer = window.setInterval(load, 60_000);
        window.addEventListener(EVENT, load);
        return () => {
            active = false;
            window.clearInterval(timer);
            window.removeEventListener(EVENT, load);
        };
    }, []);

    return count;
}

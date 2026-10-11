import { useSyncExternalStore } from 'react';

/**
 * The cart lives in the browser only. Prices here are for display; the
 * server re-prices everything at checkout.
 */
export interface CartLine {
    product_id: string;
    slug: string;
    name: string;
    price_minor: number;
    currency: string;
    quantity: number;
}

const KEY = 'nfc-cart';
const listeners = new Set<() => void>();
let cache: CartLine[] | null = null;

function read(): CartLine[] {
    if (cache) return cache;
    try {
        const parsed = JSON.parse(localStorage.getItem(KEY) ?? '[]');
        cache = Array.isArray(parsed) ? parsed : [];
    } catch {
        cache = [];
    }
    return cache;
}

function write(lines: CartLine[]) {
    cache = lines;
    try {
        localStorage.setItem(KEY, JSON.stringify(lines));
    } catch {
        // Storage unavailable (private mode): the cart still works for this page view.
    }
    listeners.forEach((l) => l());
}

export const cart = {
    add(line: Omit<CartLine, 'quantity'>, quantity = 1) {
        const lines = read();
        const existing = lines.find((l) => l.product_id === line.product_id);
        write(
            existing
                ? lines.map((l) => (l.product_id === line.product_id ? { ...l, quantity: Math.min(100, l.quantity + quantity) } : l))
                : [...lines, { ...line, quantity }],
        );
    },
    setQuantity(productId: string, quantity: number) {
        write(read().flatMap((l) => (l.product_id !== productId ? [l] : quantity > 0 ? [{ ...l, quantity: Math.min(100, quantity) }] : [])));
    },
    clear() {
        write([]);
    },
};

export function useCart(): CartLine[] {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        read,
        read,
    );
}

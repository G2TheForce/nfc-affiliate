import { useSyncExternalStore } from 'react';

export type ThemeChoice = 'light' | 'dark' | 'system';

const media = () => window.matchMedia('(prefers-color-scheme: dark)');
const listeners = new Set<() => void>();

function read(): ThemeChoice {
    try {
        const saved = localStorage.getItem('theme');
        return saved === 'light' || saved === 'dark' ? saved : 'system';
    } catch {
        return 'system';
    }
}

let choice: ThemeChoice = typeof window === 'undefined' ? 'system' : read();

function apply() {
    const dark = choice === 'dark' || (choice === 'system' && media().matches);
    document.documentElement.classList.toggle('dark', dark);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#080b19' : '#4f46e5');
}

if (typeof window !== 'undefined') {
    media().addEventListener('change', () => {
        if (choice === 'system') {
            apply();
            listeners.forEach((l) => l());
        }
    });
}

/** Light, dark, or follow the device. The choice is kept in this browser only. */
export function setTheme(next: ThemeChoice) {
    choice = next;
    try {
        if (next === 'system') localStorage.removeItem('theme');
        else localStorage.setItem('theme', next);
    } catch {
        // Storage blocked: the choice lasts for this page view.
    }
    apply();
    listeners.forEach((l) => l());
}

export function useTheme(): { choice: ThemeChoice; dark: boolean } {
    const current = useSyncExternalStore(
        (l) => {
            listeners.add(l);
            return () => listeners.delete(l);
        },
        () => `${choice}:${document.documentElement.classList.contains('dark')}`,
    );
    const [c, d] = current.split(':');
    return { choice: c as ThemeChoice, dark: d === 'true' };
}

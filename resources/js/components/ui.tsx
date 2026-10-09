import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from 'react';
import { useId } from 'react';

export function Button({
    variant = 'primary',
    loading = false,
    children,
    className = '',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary'; loading?: boolean }) {
    const styles =
        variant === 'primary'
            ? 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-500/60'
            : 'border border-line bg-surface text-ink hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-50';
    return (
        <button
            {...props}
            disabled={props.disabled || loading}
            aria-busy={loading}
            className={`inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-semibold transition ${styles} ${className}`}
        >
            {loading ? 'Please wait…' : children}
        </button>
    );
}

export function Field({
    label,
    error,
    hint,
    ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; hint?: string }) {
    const id = useId();
    const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ') || undefined;
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium text-ink">
                {label}
            </label>
            <input
                id={id}
                {...props}
                aria-invalid={!!error}
                aria-describedby={describedBy}
                className={`block min-h-11 w-full rounded-lg border bg-surface px-3 text-base text-ink shadow-sm sm:text-sm ${
                    error ? 'border-danger' : 'border-line'
                }`}
            />
            {hint && !error && (
                <p id={`${id}-hint`} className="text-xs text-muted">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

export function Alert({ tone = 'info', children }: { tone?: 'info' | 'success' | 'error'; children: ReactNode }) {
    const styles = {
        info: 'border-brand-100 bg-brand-50 text-brand-700',
        success: 'border-success/30 bg-success/10 text-success',
        error: 'border-danger/30 bg-danger/10 text-danger',
    }[tone];
    return (
        <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border px-4 py-3 text-sm ${styles}`}>
            {children}
        </div>
    );
}

export function Card({ title, children }: { title?: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-line bg-surface p-5 shadow-sm">
            {title && <h2 className="mb-3 text-base font-semibold text-ink">{title}</h2>}
            {children}
        </section>
    );
}

export function Spinner() {
    return (
        <div role="status" className="flex min-h-[40vh] items-center justify-center text-sm text-muted">
            Loading…
        </div>
    );
}

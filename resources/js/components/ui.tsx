import type React from 'react';
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
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
            ? 'bg-primary text-white shadow-sm shadow-primary/30 hover:bg-primary-hover disabled:bg-primary/60'
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
        <section className="rounded-2xl border border-line bg-surface p-5 shadow-sm">
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

export function TextArea({
    label,
    error,
    hint,
    ...props
}: React.TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string; hint?: string }) {
    const id = useId();
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium text-ink">
                {label}
            </label>
            <textarea
                id={id}
                rows={3}
                {...props}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
                className={`block w-full rounded-lg border bg-surface px-3 py-2 text-base text-ink shadow-sm sm:text-sm ${error ? 'border-danger' : 'border-line'}`}
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

export function Select({
    label,
    error,
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string }) {
    const id = useId();
    return (
        <div className="space-y-1">
            <label htmlFor={id} className="block text-sm font-medium text-ink">
                {label}
            </label>
            <select
                id={id}
                {...props}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className={`block min-h-11 w-full rounded-lg border bg-surface px-3 text-base text-ink shadow-sm sm:text-sm ${error ? 'border-danger' : 'border-line'}`}
            >
                {children}
            </select>
            {error && (
                <p id={`${id}-error`} className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

export function Stat({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
    return (
        <div className="relative overflow-hidden rounded-2xl border border-line bg-surface p-4 shadow-sm before:absolute before:inset-x-0 before:top-0 before:h-0.5 before:bg-signal before:opacity-70">
            <p className="text-sm text-muted">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-ink tabular-nums">{value}</p>
            {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
        </div>
    );
}

const STATUS_STYLES: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    pending_payment: 'bg-warning/10 text-warning',
    paid: 'bg-success/10 text-success',
    cancelled: 'bg-line text-muted',
    refunded: 'bg-line text-muted',
    attributed: 'bg-success/10 text-success',
    blocked: 'bg-danger/10 text-danger',
    none: 'bg-line text-muted',
    approved: 'bg-success/10 text-success',
    rejected: 'bg-danger/10 text-danger',
    suspended: 'bg-danger/10 text-danger',
    deactivated: 'bg-line text-muted',
    available: 'bg-brand-50 text-brand-700',
    reversed: 'bg-line text-muted',
    partially_refunded: 'bg-warning/10 text-warning',
    pending_approval: 'bg-warning/10 text-warning',
    on_hold: 'bg-danger/10 text-danger',
    clean: 'bg-success/10 text-success',
    drift: 'bg-danger/10 text-danger',
    requested: 'bg-warning/10 text-warning',
    failed: 'bg-danger/10 text-danger',
    initiated: 'bg-warning/10 text-warning',
    completed: 'bg-success/10 text-success',
    needs_review: 'bg-danger/10 text-danger',
    open: 'bg-danger/10 text-danger',
    won: 'bg-success/10 text-success',
    lost: 'bg-line text-muted',
    unfulfilled: 'bg-line text-muted',
    processing: 'bg-brand-50 text-brand-700',
    shipped: 'bg-brand-50 text-brand-700',
    delivered: 'bg-success/10 text-success',
    dismissed: 'bg-line text-muted',
    confirmed: 'bg-danger/10 text-danger',
    running: 'bg-success/10 text-success',
    upcoming: 'bg-brand-50 text-brand-700',
    ended: 'bg-line text-muted',
    active: 'bg-success/10 text-success',
    disabled: 'bg-line text-muted',
};

export function StatusBadge({ status, label }: { status: string; label?: string }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATUS_STYLES[status] ?? 'bg-line text-muted'}`}>
            {label ?? status}
        </span>
    );
}

export function Modal({ title, open, onClose, children }: { title: string; open: boolean; onClose: () => void; children: ReactNode }) {
    if (!open) return null;
    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-night/60 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            onKeyDown={(e) => e.key === 'Escape' && onClose()}
        >
            <div role="dialog" aria-modal="true" aria-label={title} className="w-full max-w-lg rounded-t-2xl bg-surface p-6 shadow-xl sm:rounded-2xl">
                <h2 className="text-lg font-semibold text-ink">{title}</h2>
                <div className="mt-4">{children}</div>
            </div>
        </div>
    );
}

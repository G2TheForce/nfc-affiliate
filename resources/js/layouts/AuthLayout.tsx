import type { ReactNode } from 'react';
import { Link } from 'react-router';

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-canvas px-4 py-10">
            <Link to="/" className="mb-6 text-lg font-bold text-brand-700">
                {import.meta.env.VITE_APP_NAME ?? 'NFC Affiliates'}
            </Link>
            <main className="w-full max-w-md rounded-2xl border border-line bg-surface p-6 shadow-sm sm:p-8">
                <h1 className="text-xl font-semibold text-ink">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
                <div className="mt-6">{children}</div>
            </main>
        </div>
    );
}

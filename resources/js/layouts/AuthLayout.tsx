import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { HeroScene, Logo, ThemeToggle } from '@/components/brand';

export function AuthLayout({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <div className="grid min-h-screen bg-canvas lg:grid-cols-2">
            <aside className="bg-night-sky relative hidden flex-col justify-between overflow-hidden p-10 text-white lg:flex" aria-hidden>
                <Link to="/" tabIndex={-1} className="[&_span]:text-white">
                    <Logo />
                </Link>
                <HeroScene />
                <p className="max-w-sm text-lg text-slate-300">
                    Share who you are with <span className="text-signal font-semibold">a single tap</span>, and earn by recommending us.
                </p>
            </aside>
            <div className="flex flex-col items-center justify-center px-4 py-10">
                <div className="mb-6 flex w-full max-w-md items-center justify-between">
                    <Link to="/" className="lg:invisible">
                        <Logo />
                    </Link>
                    <ThemeToggle />
                </div>
                <main className="w-full max-w-md animate-rise rounded-2xl border border-line bg-surface p-6 shadow-xl shadow-primary/5 sm:p-8">
                    <h1 className="text-xl font-semibold text-ink">{title}</h1>
                    {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
                    <div className="mt-6">{children}</div>
                </main>
            </div>
        </div>
    );
}

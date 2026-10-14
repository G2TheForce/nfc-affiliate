import { useState } from 'react';
import { Link, NavLink, Outlet, useNavigate } from 'react-router';
import { useUnreadCount } from '@/lib/unread';
import { useAuth } from '@/auth/AuthContext';
import { LogoMark, ThemeToggle } from '@/components/brand';

export interface NavItem {
    to: string;
    label: string;
    /** Shown when the user has this permission, or any of a list. */
    permission?: string | string[];
}

/**
 * Shared shell for the affiliate/customer area and the staff area. The nav
 * only reflects permissions for convenience; the API enforces them.
 */
export function AppLayout({ area, nav, notificationsPath }: { area: string; nav: NavItem[]; notificationsPath: string }) {
    const { user, logout, can } = useAuth();
    const unread = useUnreadCount();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const items = nav.filter((item) => !item.permission || [item.permission].flat().some((p) => can(p)));

    const onLogout = async () => {
        await logout();
        navigate('/login', { replace: true });
    };

    const links = items.map((item) => (
        <NavLink
            key={item.to}
            to={item.to}
            end
            onClick={() => setOpen(false)}
            className={({ isActive }) =>
                `relative block rounded-lg px-3 py-2 text-sm font-medium ${
                    isActive
                        ? 'bg-brand-50 text-brand-700 before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-signal'
                        : 'text-muted hover:bg-surface hover:text-ink'
                }`
            }
        >
            {item.label}
        </NavLink>
    ));

    return (
        <div className="min-h-screen bg-canvas">
            <header className="glass sticky top-0 z-20 border-b border-line">
                <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4">
                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            className="rounded-md p-2 text-muted md:hidden"
                            aria-expanded={open}
                            aria-controls="mobile-nav"
                            onClick={() => setOpen((v) => !v)}
                        >
                            <span className="sr-only">Menu</span>☰
                        </button>
                        <Link to="/" className="flex items-center gap-2" aria-label="Go to the shop">
                            <LogoMark className="h-7 w-7" />
                        </Link>
                        <span className="font-semibold text-ink">{area}</span>
                    </div>
                    <div className="flex items-center gap-3 text-sm">
                        <Link to={notificationsPath} className="relative rounded-lg px-2 py-1.5 font-medium text-muted hover:bg-canvas hover:text-ink">
                            Notifications
                            {unread > 0 && (
                                <span className="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-bold text-white">
                                    {unread}
                                    <span className="sr-only"> unread</span>
                                </span>
                            )}
                        </Link>
                        <ThemeToggle />
                        <span className="hidden items-center gap-2 text-muted sm:inline-flex">
                            <span className="bg-signal flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold text-white" aria-hidden>
                                {initials(user?.name)}
                            </span>
                            {user?.name}
                        </span>
                        <button type="button" onClick={onLogout} className="rounded-lg border border-line px-3 py-1.5 font-medium text-ink hover:bg-canvas">
                            Log out
                        </button>
                    </div>
                </div>
                {open && (
                    <nav id="mobile-nav" aria-label="Main" className="space-y-1 border-t border-line px-4 py-3 md:hidden">
                        {links}
                    </nav>
                )}
            </header>
            <div className="mx-auto flex max-w-6xl gap-6 px-4 py-6">
                <nav aria-label="Main" className="sticky top-22 hidden max-h-[calc(100vh-6rem)] w-52 shrink-0 space-y-1 self-start overflow-y-auto md:block">
                    {links}
                </nav>
                <main className="min-w-0 flex-1">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

const initials = (name?: string) =>
    (name ?? '')
        .replace(/\[[^\]]*\]/g, '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('') || '?';

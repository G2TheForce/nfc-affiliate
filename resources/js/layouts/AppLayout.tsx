import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router';
import { useAuth } from '@/auth/AuthContext';

export interface NavItem {
    to: string;
    label: string;
    permission?: string;
}

/**
 * Shared shell for the affiliate/customer area and the staff area. The nav
 * only reflects permissions for convenience; the API enforces them.
 */
export function AppLayout({ area, nav }: { area: string; nav: NavItem[] }) {
    const { user, logout, can } = useAuth();
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const items = nav.filter((item) => !item.permission || can(item.permission));

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
                `block rounded-lg px-3 py-2 text-sm font-medium ${isActive ? 'bg-brand-50 text-brand-700' : 'text-muted hover:bg-canvas hover:text-ink'}`
            }
        >
            {item.label}
        </NavLink>
    ));

    return (
        <div className="min-h-screen bg-canvas">
            <header className="sticky top-0 z-10 border-b border-line bg-surface">
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
                        <span className="font-bold text-brand-700">{area}</span>
                    </div>
                    <div className="flex items-center gap-3 text-sm">
                        <span className="hidden text-muted sm:inline">{user?.name}</span>
                        <button type="button" onClick={onLogout} className="rounded-lg border border-line px-3 py-1.5 font-medium hover:bg-canvas">
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
                <nav aria-label="Main" className="hidden w-52 shrink-0 space-y-1 md:block">
                    {links}
                </nav>
                <main className="min-w-0 flex-1">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

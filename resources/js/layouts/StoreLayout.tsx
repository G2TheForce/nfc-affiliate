import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router';
import { homePathFor, useAuth } from '@/auth/AuthContext';
import { api } from '@/lib/api';
import { useCart } from '@/store/cart';

/** Public storefront shell: shop, cart, and account links. */
export function StoreLayout() {
    const { user } = useAuth();
    const lines = useCart();
    const count = lines.reduce((n, l) => n + l.quantity, 0);
    const [referredBy, setReferredBy] = useState<string | null>(null);

    useEffect(() => {
        api<{ data: { referred: boolean; affiliate_name: string | null } }>('/store/referral')
            .then((r) => setReferredBy(r.data.referred ? r.data.affiliate_name : null))
            .catch(() => undefined);
    }, []);

    const link = ({ isActive }: { isActive: boolean }) =>
        `rounded-lg px-3 py-2 text-sm font-medium ${isActive ? 'bg-brand-50 text-brand-700' : 'text-muted hover:bg-canvas hover:text-ink'}`;

    return (
        <div className="min-h-screen bg-canvas">
            <header className="sticky top-0 z-10 border-b border-line bg-surface">
                <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-2 px-4">
                    <Link to="/" className="font-bold text-brand-700">
                        NFC Card Store
                    </Link>
                    <nav aria-label="Store" className="flex items-center gap-1">
                        <NavLink to="/shop" className={link}>
                            Shop
                        </NavLink>
                        <NavLink to="/affiliates" className={link}>
                            Earn with us
                        </NavLink>
                        <NavLink to="/cart" className={link}>
                            Cart{count > 0 && <span className="ml-1 rounded-full bg-brand-600 px-1.5 text-xs font-bold text-white">{count}</span>}
                        </NavLink>
                        {user ? (
                            <NavLink to={homePathFor(user)} className={link}>
                                My account
                            </NavLink>
                        ) : (
                            <NavLink to="/login" className={link}>
                                Log in
                            </NavLink>
                        )}
                    </nav>
                </div>
                {referredBy && (
                    <p className="border-t border-line bg-brand-50 px-4 py-1.5 text-center text-xs text-brand-700">
                        You were referred by <strong>{referredBy}</strong>.
                    </p>
                )}
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6">
                <Outlet />
            </main>
        </div>
    );
}

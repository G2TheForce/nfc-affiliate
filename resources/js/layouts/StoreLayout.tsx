import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet } from 'react-router';
import { homePathFor, useAuth } from '@/auth/AuthContext';
import { Logo, ThemeToggle } from '@/components/brand';
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
        `whitespace-nowrap rounded-lg px-2 py-2 text-sm font-medium sm:px-3 ${isActive ? 'bg-brand-50 text-brand-700' : 'text-muted hover:bg-canvas hover:text-ink'}`;

    return (
        <div className="flex min-h-screen flex-col bg-canvas">
            <header className="glass sticky top-0 z-20 border-b border-line">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-2 px-4">
                    <Link to="/">
                        <Logo compact />
                    </Link>
                    <nav aria-label="Store" className="flex items-center gap-0.5 sm:gap-1">
                        <NavLink to="/shop" className={({ isActive }) => `hidden sm:block ${link({ isActive })}`}>
                            Shop
                        </NavLink>
                        <NavLink to="/affiliates" className={link}>
                            Earn with us
                        </NavLink>
                        <NavLink to="/cart" className={link}>
                            Cart
                            {count > 0 && <span className="ml-1 rounded-full bg-primary px-1.5 text-xs font-bold text-white">{count}</span>}
                        </NavLink>
                        {user ? (
                            <NavLink to={homePathFor(user)} className={link}>
                                Account
                            </NavLink>
                        ) : (
                            <NavLink to="/login" className={link}>
                                Log in
                            </NavLink>
                        )}
                        <ThemeToggle />
                    </nav>
                </div>
                {referredBy && (
                    <p className="border-t border-line bg-brand-50 px-4 py-1.5 text-center text-xs text-brand-700">
                        You were referred by <strong>{referredBy}</strong>.
                    </p>
                )}
            </header>
            <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
                <Outlet />
            </main>
            <footer className="border-t border-line bg-surface">
                <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 text-sm sm:grid-cols-3">
                    <div className="space-y-2">
                        <Logo />
                        <p className="text-muted">Smart NFC business cards and tap products, made for Nepal.</p>
                    </div>
                    <div className="space-y-1">
                        <p className="font-semibold text-ink">Shop with confidence</p>
                        <p className="text-muted">Pay with eSewa, Khalti or cash on delivery.</p>
                        <p className="text-muted">Delivery across Nepal.</p>
                    </div>
                    <nav aria-label="Footer" className="flex flex-col items-start gap-1">
                        <Link to="/shop" className="text-muted hover:text-ink">
                            All products
                        </Link>
                        <Link to="/affiliates" className="text-muted hover:text-ink">
                            Affiliate programme
                        </Link>
                        <Link to={user ? homePathFor(user) : '/login'} className="text-muted hover:text-ink">
                            {user ? 'My account' : 'Log in'}
                        </Link>
                    </nav>
                </div>
            </footer>
        </div>
    );
}

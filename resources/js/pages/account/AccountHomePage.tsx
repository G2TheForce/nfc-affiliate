import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { CardArt } from '@/components/brand';
import { Card } from '@/components/ui';

export default function AccountHomePage() {
    const { user, can } = useAuth();

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Welcome, {user?.name}</h1>
            <Link
                to="/account/card"
                className="bg-night-sky group flex flex-col items-center gap-5 overflow-hidden rounded-3xl p-6 text-white sm:flex-row sm:p-8"
            >
                <div className="w-48 shrink-0 rotate-[-6deg] rounded-xl shadow-2xl shadow-signal-violet/30 transition group-hover:rotate-0">
                    <CardArt seed="account" palette={['#0b1026', '#4f46e5', '#22d3ee']} title={user?.name} />
                </div>
                <div className="space-y-2">
                    <p className="text-lg font-semibold">Your tap card</p>
                    <p className="text-sm text-slate-300">
                        Set up the page your NFC card opens: call and WhatsApp buttons, socials and Save contact. Get a QR code, or write it to a tag from your phone.
                    </p>
                    <span className="inline-block text-sm font-semibold text-cyan-200">Set up or edit →</span>
                </div>
            </Link>
            <Card title="Affiliate programme">
                <p className="text-sm text-muted">
                    Promote our NFC cards and earn a commission on eligible paid orders from your referrals.
                </p>
                <Link
                    to={can('affiliate.portal') ? '/account/affiliate/dashboard' : '/account/affiliate'}
                    className="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline"
                >
                    {can('affiliate.portal') ? 'Open your affiliate dashboard' : user?.roles.includes('affiliate') ? 'Open your affiliate area' : 'Apply or check your application'}
                </Link>
            </Card>
            <Card title="Your account">
                <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted">Email</dt>
                        <dd className="font-medium text-ink">{user?.email}</dd>
                    </div>
                    <div>
                        <dt className="text-muted">Phone</dt>
                        <dd className="font-medium text-ink">{user?.phone ?? 'Not provided'}</dd>
                    </div>
                </dl>
            </Card>
        </div>
    );
}

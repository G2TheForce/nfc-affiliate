import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Card } from '@/components/ui';

export default function AccountHomePage() {
    const { user, can } = useAuth();

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Welcome, {user?.name}</h1>
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

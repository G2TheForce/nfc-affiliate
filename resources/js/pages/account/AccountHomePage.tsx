import { useAuth } from '@/auth/AuthContext';
import { Card } from '@/components/ui';

export default function AccountHomePage() {
    const { user } = useAuth();

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Welcome, {user?.name}</h1>
            <Card title="Affiliate programme">
                <p className="text-sm text-muted">
                    Affiliate applications open in the next release. Once your application is approved, your referral links, earnings and payouts
                    will appear here.
                </p>
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

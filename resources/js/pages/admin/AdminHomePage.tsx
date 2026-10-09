import { useAuth } from '@/auth/AuthContext';
import { Card } from '@/components/ui';

export default function AdminHomePage() {
    const { user } = useAuth();

    return (
        <div className="space-y-6">
            <h1 className="text-2xl font-semibold text-ink">Staff dashboard</h1>
            <Card title="Signed in as">
                <p className="text-sm text-ink">
                    {user?.name} · <span className="text-muted">{user?.roles.join(', ')}</span>
                </p>
            </Card>
            <Card title="Coming next">
                <p className="text-sm text-muted">
                    Affiliate applications, commissions and payouts are added in the following phases. Figures will appear here only once they come
                    from real records.
                </p>
            </Card>
        </div>
    );
}

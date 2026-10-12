import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Alert, Card, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';

interface Programme {
    currency: string;
    standard_rate: { kind: 'percent' | 'fixed'; rate_bp: number | null; fixed_minor: number | null } | null;
    attribution_window_days: number;
    holding_period_days: number;
    payout_minimum_minor: number;
    payout_methods: string[];
    terms: { version: string; title: string; body: string; published_at: string } | null;
}

/** Public page explaining the affiliate programme, from live settings. */
export default function AffiliateProgrammePage() {
    const { user } = useAuth();
    const [data, setData] = useState<Programme | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [showTerms, setShowTerms] = useState(false);

    useEffect(() => {
        api<{ data: Programme }>('/store/affiliate-programme')
            .then((r) => setData(r.data))
            .catch((e) => setError(e instanceof ApiError ? e.message : 'Could not load the programme details.'));
    }, []);

    if (error) return <Alert tone="error">{error}</Alert>;
    if (!data) return <Spinner />;

    const rate = data.standard_rate
        ? data.standard_rate.kind === 'percent'
            ? `${(data.standard_rate.rate_bp ?? 0) / 100}% of the product price after discounts`
            : `${formatMoney(data.standard_rate.fixed_minor ?? 0, data.currency)} per item sold`
        : null;
    const cta = user ? '/account/affiliate' : '/register';

    return (
        <div className="mx-auto max-w-3xl space-y-6">
            <div className="space-y-2">
                <h1 className="text-3xl font-semibold text-ink">Earn by recommending our NFC cards</h1>
                <p className="text-muted">
                    Share your link or coupon code with your followers, customers or friends. When they buy, you earn commission, paid to your bank account, eSewa or Khalti.
                </p>
                <Link to={cta} className="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-5 font-semibold text-white hover:bg-brand-700">
                    {user ? 'Apply from your account' : 'Create an account to apply'}
                </Link>
            </div>

            <Card title="How it works">
                <ol className="list-decimal space-y-2 pl-5 text-sm text-ink">
                    <li>Create a free account and apply. Tell us where you will promote our cards. We review every application.</li>
                    <li>Once approved, you get a referral code, links you can name for each place you share them, and a coupon if we give you one.</li>
                    <li>
                        A customer who follows your link is credited to you for {data.attribution_window_days} days, and the last affiliate link they followed counts. A customer who types
                        your code or coupon at checkout is always credited to you.
                    </li>
                    <li>Commission is earned when the order is paid. It is pending for {data.holding_period_days} days in case of refunds, then joins your available balance.</li>
                    <li>
                        Request a payout once your available balance reaches {formatMoney(data.payout_minimum_minor, data.currency)}. We pay by {data.payout_methods.join(', ')}.
                    </li>
                </ol>
            </Card>

            <Card title="Commission">
                <p className="text-sm text-ink">
                    {rate ? (
                        <>
                            Standard rate: <strong>{rate}</strong>. Delivery charges are not included. Some products or partners may have different rates; yours are shown under My rates
                            once you are approved.
                        </>
                    ) : (
                        'Rates are shown in your account once you are approved.'
                    )}
                </p>
                <p className="mt-2 text-sm text-muted">If an order is refunded, the commission on the refunded part is reversed, even if it was already paid out.</p>
            </Card>

            <Card title="Who can join">
                <ul className="list-disc space-y-1 pl-5 text-sm text-ink">
                    <li>Anyone with a verified account who can promote honestly, such as creators, shops, agencies and community pages.</li>
                    <li>You cannot earn on your own purchases.</li>
                    <li>No spam, fake reviews or misleading claims. The programme terms below set out the full rules.</li>
                </ul>
            </Card>

            <Card title="Questions">
                <dl className="space-y-3 text-sm">
                    <div>
                        <dt className="font-medium text-ink">Does it cost anything?</dt>
                        <dd className="text-muted">No. Joining is free.</dd>
                    </div>
                    <div>
                        <dt className="font-medium text-ink">How do I see my sales?</dt>
                        <dd className="text-muted">Your affiliate dashboard shows clicks, orders, earnings and payouts. Customer names are never shown.</dd>
                    </div>
                    <div>
                        <dt className="font-medium text-ink">When am I paid?</dt>
                        <dd className="text-muted">After you request a payout, our finance team reviews it and sends the transfer. You get an email at each step.</dd>
                    </div>
                    <div>
                        <dt className="font-medium text-ink">Is tax deducted?</dt>
                        <dd className="text-muted">Nothing is deducted at the moment. You are responsible for declaring your earnings.</dd>
                    </div>
                </dl>
            </Card>

            <Card title="Programme terms">
                {data.terms ? (
                    <>
                        <p className="text-sm text-muted">
                            {data.terms.title}, version {data.terms.version}, published {formatDate(data.terms.published_at)}. You accept them when you apply.
                        </p>
                        <button type="button" className="mt-2 text-sm font-medium text-brand-700 underline" onClick={() => setShowTerms((v) => !v)} aria-expanded={showTerms}>
                            {showTerms ? 'Hide the terms' : 'Read the terms'}
                        </button>
                        {showTerms && <div className="mt-3 max-h-96 overflow-y-auto whitespace-pre-line rounded-lg border border-line p-3 text-sm text-ink">{data.terms.body}</div>}
                    </>
                ) : (
                    <p className="text-sm text-muted">The terms will be published here before applications open.</p>
                )}
            </Card>
        </div>
    );
}

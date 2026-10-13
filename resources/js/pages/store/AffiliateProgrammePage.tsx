import { useEffect, useState } from 'react';
import { Link } from 'react-router';
import { useAuth } from '@/auth/AuthContext';
import { Illustration } from '@/components/brand';
import { Alert, Card, Spinner } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';

interface Programme {
    currency: string;
    standard_rate: {
        kind: 'percent' | 'fixed';
        rate_bp: number | null;
        fixed_minor: number | null;
    } | null;
    attribution_window_days: number;
    holding_period_days: number;
    payout_minimum_minor: number;
    payout_methods: string[];
    terms: {
        version: string;
        title: string;
        body: string;
        published_at: string;
    } | null;
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
            <section className="bg-night-sky relative overflow-hidden rounded-3xl px-6 py-10 text-white sm:px-10">
                <div className="space-y-4">
                    <p className="text-sm font-medium text-cyan-200">Affiliate programme</p>
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                        Earn by recommending <span className="text-signal">our NFC cards</span>
                    </h1>
                    <p className="max-w-xl text-slate-300">
                        Share your link or coupon code with your followers, customers or friends. When they buy, you earn commission, paid to your bank account,
                        eSewa or Khalti.
                    </p>
                    <Link to={cta} className="inline-flex min-h-11 items-center rounded-lg bg-white px-5 font-semibold text-slate-900 hover:bg-cyan-50">
                        {user ? 'Apply from your account' : 'Create an account to apply'}
                    </Link>
                </div>
                {rate && (
                    <p className="mt-6 inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-1.5 text-sm">
                        Standard rate: <strong className="ml-1">{rate}</strong>
                    </p>
                )}
            </section>

            <ul className="grid gap-4 sm:grid-cols-3">
                {(
                    [
                        ['share', 'Share your link', 'Links for each place you post, plus your own code.'],
                        ['tap', 'They buy a card', `Credited to you for ${data.attribution_window_days} days after their click.`],
                        ['earn', 'You get paid', `Paid by ${data.payout_methods.join(', ')} once your balance is available.`],
                    ] as const
                ).map(([kind, title, body]) => (
                    <li key={kind} className="flex flex-col items-center rounded-2xl border border-line bg-surface p-5 text-center shadow-sm">
                        <Illustration kind={kind} className="h-20 w-20" />
                        <p className="mt-2 font-semibold text-ink">{title}</p>
                        <p className="mt-1 text-sm text-muted">{body}</p>
                    </li>
                ))}
            </ul>

            <Card title="How it works">
                <ol className="list-decimal space-y-2 pl-5 text-sm text-ink">
                    <li>Create a free account and apply. Tell us where you will promote our cards. We review every application.</li>
                    <li>Once approved, you get a referral code, links you can name for each place you share them, and a coupon if we give you one.</li>
                    <li>
                        A customer who follows your link is credited to you for {data.attribution_window_days} days, and the last affiliate link they followed
                        counts. A customer who types your code or coupon at checkout is always credited to you.
                    </li>
                    <li>
                        Commission is earned when the order is paid. It is pending for {data.holding_period_days} days in case of refunds, then joins your
                        available balance.
                    </li>
                    <li>
                        Request a payout once your available balance reaches {formatMoney(data.payout_minimum_minor, data.currency)}. We pay by{' '}
                        {data.payout_methods.join(', ')}.
                    </li>
                </ol>
            </Card>

            <Card title="Commission">
                <p className="text-sm text-ink">
                    {rate ? (
                        <>
                            Standard rate: <strong>{rate}</strong>. Delivery charges are not included. Some products or partners may have different rates; yours
                            are shown under My rates once you are approved.
                        </>
                    ) : (
                        'Rates are shown in your account once you are approved.'
                    )}
                </p>
                <p className="mt-2 text-sm text-muted">
                    If an order is refunded, the commission on the refunded part is reversed, even if it was already paid out.
                </p>
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
                        <dd className="text-muted">
                            After you request a payout, our finance team reviews it and sends the transfer. You get an email at each step.
                        </dd>
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
                        <button
                            type="button"
                            className="mt-2 text-sm font-medium text-brand-700 underline"
                            onClick={() => setShowTerms((v) => !v)}
                            aria-expanded={showTerms}
                        >
                            {showTerms ? 'Hide the terms' : 'Read the terms'}
                        </button>
                        {showTerms && (
                            <div className="mt-3 max-h-96 overflow-y-auto whitespace-pre-line rounded-lg border border-line p-3 text-sm text-ink">
                                {data.terms.body}
                            </div>
                        )}
                    </>
                ) : (
                    <p className="text-sm text-muted">The terms will be published here before applications open.</p>
                )}
            </Card>
        </div>
    );
}

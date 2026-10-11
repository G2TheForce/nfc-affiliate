import { useEffect, useId, useState } from 'react';
import { api } from '@/lib/api';
import type { Option } from './types';

interface Options {
    affiliates: Option[];
    products: Option[];
    coupons: Option[];
}

const selectClass = 'block min-h-11 w-full rounded-lg border border-line bg-surface px-3 text-base text-ink shadow-sm sm:text-sm';

/**
 * Affiliate, product and coupon filters for staff dashboards and reports.
 * Affiliates are searched by name or referral code; `show` limits the
 * filters to the ones a report supports.
 */
export function FilterBar({
    values,
    onChange,
    show = ['affiliate_id', 'product_id', 'coupon_id'],
}: {
    values: Record<string, string>;
    onChange: (changes: Record<string, string>) => void;
    show?: string[];
}) {
    const uid = useId();
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<Options | null>(null);
    const selectedAffiliate = values.affiliate_id ?? '';

    useEffect(() => {
        let active = true;
        const timer = setTimeout(() => {
            const query = new URLSearchParams();
            if (search) query.set('q', search);
            if (selectedAffiliate) query.set('affiliate_id', selectedAffiliate);
            api<{ data: Options }>(`/admin/dashboard/filter-options?${query}`)
                .then((r) => active && setOptions(r.data))
                .catch(() => undefined);
        }, 250);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [search, selectedAffiliate]);

    if (show.length === 0) return null;
    const active = show.filter((k) => values[k]).length;

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {show.includes('affiliate_id') && (
                <>
                    <div className="block space-y-1 text-sm">
                        <label htmlFor={`${uid}-1`} className="block font-medium text-ink">Find affiliate</label>
                        <input id={`${uid}-1`} type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Name or referral code" className={selectClass} />
                    </div>
                    <div className="block space-y-1 text-sm">
                        <label htmlFor={`${uid}-2`} className="block font-medium text-ink">Affiliate</label>
                        <select id={`${uid}-2`} value={selectedAffiliate} onChange={(e) => onChange({ affiliate_id: e.target.value })} className={selectClass}>
                            <option value="">All affiliates</option>
                            {options?.affiliates.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </>
            )}
            {show.includes('product_id') && (
                <div className="block space-y-1 text-sm">
                    <label htmlFor={`${uid}-3`} className="block font-medium text-ink">Product</label>
                    <select id={`${uid}-3`} value={values.product_id ?? ''} onChange={(e) => onChange({ product_id: e.target.value })} className={selectClass}>
                        <option value="">All products</option>
                        {options?.products.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {show.includes('coupon_id') && (
                <div className="block space-y-1 text-sm">
                    <label htmlFor={`${uid}-4`} className="block font-medium text-ink">Coupon campaign</label>
                    <select id={`${uid}-4`} value={values.coupon_id ?? ''} onChange={(e) => onChange({ coupon_id: e.target.value })} className={selectClass}>
                        <option value="">All orders</option>
                        {options?.coupons.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {active > 0 && (
                <div className="flex items-end sm:col-span-2 lg:col-span-4">
                    <button
                        type="button"
                        onClick={() => onChange(Object.fromEntries(show.map((k) => [k, ''])))}
                        className="min-h-11 text-sm font-semibold text-brand-600 hover:underline"
                    >
                        Clear {active === 1 ? 'filter' : `${active} filters`}
                    </button>
                </div>
            )}
        </div>
    );
}

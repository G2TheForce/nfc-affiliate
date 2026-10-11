export type Buckets = Record<'pending' | 'approved' | 'available' | 'reserved' | 'paid' | 'reversed', number>;

export interface CommissionRow {
    id: string;
    order_number: string;
    order_id?: string;
    affiliate?: { id: string; display_name: string; referral_code: string | null } | null;
    product_name: string | null;
    sku: string | null;
    quantity: number;
    kind: 'percent' | 'fixed';
    rate_bp: number | null;
    fixed_minor: number | null;
    base_minor: number;
    amount_minor: number;
    reversed_minor: number;
    net_minor: number;
    currency: string;
    status: string;
    status_label: string;
    partially_reversed: boolean;
    on_hold: boolean;
    hold_reason?: string | null;
    rule?: { id: string | null; name: string | null; scope: string; version: number };
    eligible_at: string;
    approved_at: string | null;
    available_at: string | null;
    reversed_at: string | null;
    created_at: string;
}

export interface LedgerEntry {
    type: string;
    label: string;
    from_bucket: string | null;
    to_bucket: string | null;
    amount_minor: number;
    currency: string;
    commission_id: string | null;
    adjustment_id: string | null;
    created_at: string;
}

export interface Paginated<T> {
    data: T[];
    meta?: { current_page: number; last_page: number; total: number };
}

/** "10%" from basis points, or "NPR 125 per unit" for fixed rules. */
export function rateLabel(kind: string, rateBp: number | null, fixedMinor: number | null, currency = 'NPR'): string {
    if (kind === 'percent' && rateBp !== null) return `${(rateBp / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 })}%`;
    if (kind === 'fixed' && fixedMinor !== null) return `${currency} ${(fixedMinor / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 })} per unit`;
    if (kind === 'exclude') return 'No commission';
    return '—';
}

export const BUCKET_LABELS: Record<string, string> = {
    pending: 'Pending',
    approved: 'Approved',
    available: 'Available',
    reserved: 'Reserved',
    paid: 'Paid',
    reversed: 'Reversed',
};

/** Ledger movement as words, e.g. "Pending → Available". */
export function movement(e: LedgerEntry): string {
    const from = e.from_bucket ? BUCKET_LABELS[e.from_bucket] : 'Earned';
    const to = e.to_bucket ? BUCKET_LABELS[e.to_bucket] : 'Deducted';
    return `${from} → ${to}`;
}

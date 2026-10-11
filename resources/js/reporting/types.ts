export interface PeriodInfo {
    from: string;
    to: string;
    grain: 'day' | 'month';
    timezone: string;
}

export interface Totals {
    clicks: number;
    unique_clicks: number;
    attributed_orders: number;
    conversion_rate: number | null;
    paid_orders: number;
    revenue_minor: number;
    commissionable_minor: number;
    commission_earned_minor: number;
    commission_reversed_minor: number;
    refunded_minor: number;
}

export interface Balances {
    pending: number;
    approved: number;
    available: number;
    reserved: number;
    paid: number;
    reversed: number;
}

export interface SeriesPoint {
    bucket: string;
    clicks: number;
    unique_clicks: number;
    orders: number;
    conversion_rate: number | null;
    revenue_minor: number;
    earned_minor: number;
}

export interface ProductRow {
    product_id: string;
    name: string;
    units: number;
    orders: number;
    revenue_minor: number;
    commission_minor: number;
}

export interface CampaignRow {
    coupon_id: string | null;
    code: string;
    affiliate: string | null;
    orders: number;
    revenue_minor: number;
    commission_minor: number;
}

export interface AffiliateRow {
    affiliate_id: string;
    display_name: string;
    referral_code: string;
    status: string;
    clicks: number;
    unique_clicks: number;
    orders: number;
    conversion_rate: number | null;
    revenue_minor: number;
    commission_earned_minor: number;
    commission_reversed_minor: number;
}

export interface LinkRow {
    link_id: string | null;
    name: string;
    clicks: number;
    unique_clicks: number;
    orders: number;
}

export interface PayoutQueue {
    awaiting_review: { count: number; amount_minor: number };
    approved_unpaid: { count: number; amount_minor: number };
    oldest_requested_at: string | null;
}

export type Definitions = Record<string, string>;

export interface ReportColumn {
    key: string;
    label: string;
    type: 'text' | 'int' | 'money' | 'percent' | 'datetime' | 'date';
}

export interface ReportInfo {
    key: string;
    title: string;
    description: string;
    uses_period: boolean;
    filters: string[];
    columns: ReportColumn[];
}

export interface Option {
    id: string;
    label: string;
}

import type { CommissionRow } from '@/commissions/types';
import type { OrderItem } from '@/store/types';

export interface AdminOrderItem extends OrderItem {
    id: string;
    refunded_minor: number;
}

export interface AdminOrder {
    id: string;
    order_number: string;
    status: string;
    status_label: string;
    currency: string;
    subtotal_minor: number;
    discount_minor: number;
    shipping_minor: number;
    total_minor: number;
    refunded_minor: number;
    coupon_code: string | null;
    customer_name: string;
    customer_email: string;
    customer_phone: string;
    shipping_city: string;
    placed_at: string;
    paid_at: string | null;
    cancelled_at: string | null;
    cancel_reason?: string | null;
    items?: AdminOrderItem[];
    payment?: {
        id: string;
        provider: string;
        method: string;
        method_label: string;
        reference: string;
        amount_minor: number;
        note: string | null;
        confirmed_by: string | null;
        confirmed_at: string;
    } | null;
    refunds?: {
        id: string;
        reference: string;
        amount_minor: number;
        shipping_minor: number;
        reason: string;
        items: { sku: string | null; amount_minor: number }[];
        recorded_by: string | null;
        completed_at: string;
    }[];
    commissions?: CommissionRow[];
    attribution?: {
        status: string;
        block_reason: string | null;
        affiliate: { id: string; display_name: string; referral_code: string | null } | null;
        source: string | null;
        source_label: string | null;
        policy: string | null;
        clicked_at: string | null;
        click: { landing_path: string; referrer_host: string | null; utm_source: string | null; utm_medium: string | null; utm_campaign: string | null; sub_id: string | null; link: string | null } | null;
        decided_by: string | null;
        reason: string | null;
    };
}

export const BLOCK_REASONS: Record<string, string> = {
    self_referral: 'Self-referral (buyer is the affiliate)',
    affiliate_not_eligible: 'Affiliate was not active',
};

export const PAYMENT_METHODS = [
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'cash_on_delivery', label: 'Cash on delivery' },
    { value: 'esewa', label: 'eSewa (checked in merchant dashboard)' },
    { value: 'khalti', label: 'Khalti (checked in merchant dashboard)' },
    { value: 'fonepay', label: 'Fonepay QR (checked in merchant dashboard)' },
    { value: 'other', label: 'Other' },
];

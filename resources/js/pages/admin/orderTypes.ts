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
    fulfilment_status: 'unfulfilled' | 'processing' | 'shipped' | 'delivered';
    fulfilment_label: string;
    fulfilment?: { processing_at: string | null; shipped_at: string | null; carrier: string | null; tracking_number: string | null; delivered_at: string | null };
    payment_attempts?: PaymentAttempt[];
    disputes?: OrderDispute[];
    items?: AdminOrderItem[];
    payment?: {
        id: string;
        provider: string;
        verified_by: 'staff' | 'provider';
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

export interface PaymentAttempt {
    id: string;
    order?: { id: string; order_number: string };
    provider: string;
    provider_reference: string | null;
    provider_transaction_id: string | null;
    amount_minor: number;
    currency: string;
    status: 'initiated' | 'pending' | 'completed' | 'failed' | 'needs_review';
    status_label: string;
    provider_status: string | null;
    review_reason: string | null;
    reviewed_by?: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    check_count: number;
    last_checked_at: string | null;
    next_check_at: string | null;
    completed_at: string | null;
    created_at: string;
}

export interface OrderDispute {
    id: string;
    reference: string;
    amount_minor: number;
    reason: string;
    status: 'open' | 'won' | 'lost';
    status_label: string;
    opened_by: string | null;
    opened_at: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    resolution_note: string | null;
    held_commissions: number;
}

export const PROVIDER_LABELS: Record<string, string> = { esewa: 'eSewa', khalti: 'Khalti' };

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

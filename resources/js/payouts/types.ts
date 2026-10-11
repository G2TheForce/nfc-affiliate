export interface Payout {
    id: string;
    amount_minor: number;
    currency: string;
    status: 'requested' | 'approved' | 'paid' | 'rejected' | 'failed' | 'cancelled';
    status_label: string;
    method_type: string;
    method_label: string;
    destination: string;
    requested_at: string;
    reviewed_at: string | null;
    paid_at: string | null;
    closed_at: string | null;
    payment_reference: string | null;
    reason: string | null;
    commission_count?: number;
    can_cancel: boolean;
}

export interface StaffPayout extends Payout {
    affiliate: { id: string; display_name: string; referral_code: string | null; status: string };
    account_name: string;
    review_note: string | null;
    failure_reason: string | null;
    reviewed_by: string | null;
    paid_by: string | null;
    can_review: boolean;
    can_reject: boolean;
    can_mark_paid: boolean;
    can_mark_failed: boolean;
}

export interface PayoutMethod {
    type: string;
    type_label: string;
    account_name: string;
    masked: string;
    updated_at: string | null;
}

export const METHOD_TYPES = [
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'esewa', label: 'eSewa' },
    { value: 'khalti', label: 'Khalti' },
];

/** What each status means for the affiliate, in plain words. */
export const STATUS_HELP: Record<string, string> = {
    requested: 'Waiting for our finance team to review.',
    approved: 'Approved. The transfer will be made soon.',
    paid: 'Sent.',
    rejected: 'Declined. The amount went back to your available balance.',
    failed: 'The transfer did not go through. The amount went back to your available balance.',
    cancelled: 'You cancelled this request. The amount went back to your available balance.',
};

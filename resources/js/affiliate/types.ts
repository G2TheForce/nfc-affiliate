export interface AffiliateProfile {
    id: string;
    status: 'pending' | 'approved' | 'rejected' | 'suspended' | 'deactivated';
    status_label: string;
    display_name: string;
    referral_code: string | null;
    referral_slug: string | null;
    country_code: string;
    city: string | null;
    address_line: string | null;
    promotion_channels: string[];
    website_urls: string[];
    audience_description: string | null;
    applied_at: string | null;
    approved_at: string | null;
    can_reapply_at: string | null;
    needs_terms_acceptance: boolean;
    current_terms_version: string | null;
    history: { status: string; status_label: string; reason: string | null; at: string | null }[];
    terms_accepted: { version: string; accepted_at: string | null }[];
}

export interface Terms {
    version: string;
    title: string;
    body: string;
    published_at: string;
}

<?php

namespace App\Enums;

/**
 * Order lifecycle events recorded per affiliate. Clicks live in
 * referral_clicks. A click or a placed order is never a completed sale:
 * order_paid and later events arrive with commission accounting (Phase 4).
 */
enum ReferralEventType: string
{
    case OrderPlaced = 'order_placed';
    case OrderCancelled = 'order_cancelled';
    case OrderPaid = 'order_paid';
    case OrderRefunded = 'order_refunded';
    case CommissionApproved = 'commission_approved';
}

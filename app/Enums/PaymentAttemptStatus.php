<?php

namespace App\Enums;

/**
 * An online payment attempt. Only a server-side status check moves it to
 * completed; the browser redirect alone never does.
 */
enum PaymentAttemptStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    // Money may have moved but the order cannot simply be marked paid
    // (amount mismatch, order already paid or cancelled, provider refund,
    // still pending after the give-up time). Finance decides.
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Started',
            self::Pending => 'Waiting for the provider',
            self::Completed => 'Paid',
            self::Failed => 'Not paid',
            self::NeedsReview => 'Needs review',
        };
    }

    /** Still worth asking the provider about. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Initiated, self::Pending], true);
    }
}

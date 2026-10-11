<?php

namespace App\Enums;

/**
 * Affiliate membership lifecycle. Applications start as Pending; there is no
 * draft state in v1 (the form is short enough to submit in one go).
 */
enum AffiliateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected],
            self::Rejected => [self::Pending],            // re-application after the cooldown
            self::Approved => [self::Suspended, self::Deactivated],
            self::Suspended => [self::Approved, self::Deactivated],
            self::Deactivated => [self::Approved],        // staff reactivation with a reason
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Only approved members may earn commissions or create referral links. */
    public function isEligibleToEarn(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Not approved',
            self::Suspended => 'Suspended',
            self::Deactivated => 'Deactivated',
        };
    }
}

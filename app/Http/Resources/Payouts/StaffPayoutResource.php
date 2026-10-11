<?php

namespace App\Http\Resources\Payouts;

use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Models\Payout;
use Illuminate\Http\Request;

/**
 * A payout for finance: who it is for, who reviewed and paid it, and which
 * actions the viewer may take now. Full account details are added only by
 * PayoutController::show for staff who can mark payouts paid.
 *
 * @mixin Payout
 */
class StaffPayoutResource extends PayoutResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canPay = $user?->can(Permission::PayoutsMarkPaid->value) ?? false;
        $separate = (bool) config('affiliate.payouts.require_separate_payer');

        return array_merge(parent::toArray($request), [
            'affiliate' => [
                'id' => $this->affiliate->public_id,
                'display_name' => $this->affiliate->display_name,
                'referral_code' => $this->affiliate->referral_code,
                'status' => $this->affiliate->status->value,
            ],
            'account_name' => $this->account_name,
            'review_note' => $this->review_note,
            'failure_reason' => $this->failure_reason,
            'reviewed_by' => $this->reviewer?->name,
            'paid_by' => $this->payer?->name,
            'can_cancel' => false,
            'can_review' => $this->status === PayoutStatus::Requested && ($user?->can(Permission::PayoutsReview->value) ?? false),
            'can_reject' => $this->status->isOpen() && ($user?->can(Permission::PayoutsReview->value) ?? false),
            'can_mark_paid' => $this->status === PayoutStatus::Approved && $canPay && ! ($separate && $this->reviewed_by === $user?->id),
            'can_mark_failed' => $this->status === PayoutStatus::Approved && $canPay,
        ]);
    }
}

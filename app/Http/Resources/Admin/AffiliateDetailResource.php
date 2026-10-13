<?php

namespace App\Http\Resources\Admin;

use App\Enums\Permission;
use App\Models\AffiliateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff view: includes internal notes and possible-duplicate signals.
 *
 * @mixin AffiliateProfile
 */
class AffiliateDetailResource extends JsonResource
{
    /**
     * @param  list<array<string, mixed>>  $duplicates
     * @param  array<string, mixed>|null  $account  sign-in summary from AffiliateAccountAccess
     */
    public function __construct(AffiliateProfile $resource, private readonly array $duplicates = [], private readonly ?array $account = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $staff = $request->user();

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'allowed_transitions' => array_map(fn ($s) => $s->value, $this->status->allowedTransitions()),
            'display_name' => $this->display_name,
            'user' => [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'email_verified' => $this->user->hasVerifiedEmail(),
                'registered_at' => $this->user->created_at?->toIso8601String(),
            ],
            'referral_code' => $this->referral_code,
            'commission_plan' => $this->plan ? ['id' => $this->plan->public_id, 'name' => $this->plan->name] : null,
            'referral_slug' => $this->referral_slug,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'address_line' => $this->address_line,
            'promotion_channels' => $this->promotion_channels,
            'website_urls' => $this->website_urls ?? [],
            'audience_description' => $this->audience_description,
            'applied_at' => $this->applied_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approver?->name,
            'history' => $this->statusHistory->map(fn ($h) => [
                'from' => $h->from_status?->value,
                'to' => $h->to_status->value,
                'actor' => $h->actor?->name,
                'reason' => $h->public_reason,
                'internal_note' => $h->internal_note,
                'at' => $h->created_at?->toIso8601String(),
            ])->values(),
            'notes' => $this->notes->map(fn ($n) => [
                'author' => $n->author?->name,
                'body' => $n->body,
                'at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'terms_accepted' => $this->termsAcceptances->map(fn ($a) => [
                'version' => $a->termsVersion->version,
                'accepted_at' => $a->accepted_at?->toIso8601String(),
            ])->values(),
            'possible_duplicates' => $this->duplicates,
            'account' => $this->account,
            'can' => [
                'review' => $staff->can(Permission::AffiliatesReview->value),
                'suspend' => $staff->can(Permission::AffiliatesSuspend->value),
            ],
        ];
    }
}

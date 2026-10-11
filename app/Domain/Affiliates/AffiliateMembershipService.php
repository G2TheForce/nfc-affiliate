<?php

namespace App\Domain\Affiliates;

use App\Domain\Audit\AuditLogger;
use App\Enums\AffiliateStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\AffiliateTermsVersion;
use App\Models\User;
use App\Notifications\Affiliate\AffiliateStatusChanged;
use App\Notifications\Affiliate\ApplicationReceived;
use App\Notifications\Affiliate\NewApplicationForReview;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Every affiliate membership change goes through here: one transaction, a
 * row lock on the profile, a state-machine check, a status history row, an
 * audit entry, and notifications sent only after the commit.
 */
class AffiliateMembershipService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReferralIdentifierGenerator $identifiers,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $details  validated application fields
     */
    public function apply(User $user, array $details, AffiliateTermsVersion $terms): AffiliateProfile
    {
        $this->assertCanApply($user);

        return DB::transaction(function () use ($user, $details, $terms) {
            /** @var AffiliateProfile|null $existing */
            $existing = AffiliateProfile::where('user_id', $user->id)->lockForUpdate()->first();

            if ($existing) {
                return $this->reapply($existing, $details, $terms);
            }

            $profile = new AffiliateProfile($details);
            $profile->user_id = $user->id;
            $profile->status = AffiliateStatus::Pending;
            $profile->applied_at = now();
            $profile->status_changed_at = now();
            $profile->save();

            $profile->statusHistory()->create(['from_status' => null, 'to_status' => AffiliateStatus::Pending, 'actor_id' => $user->id]);
            $this->recordTermsAcceptance($profile, $terms);
            $this->audit->log('affiliate.applied', $profile, [], $user);
            $this->notifyApplication($profile);

            return $profile;
        });
    }

    public function approve(AffiliateProfile $profile, User $actor, ?string $internalNote = null): AffiliateProfile
    {
        return $this->transition($profile, AffiliateStatus::Approved, $actor, [AffiliateStatus::Pending], null, $internalNote, 'affiliate.approved');
    }

    public function reject(AffiliateProfile $profile, User $actor, string $publicReason, ?string $internalNote = null): AffiliateProfile
    {
        return $this->transition($profile, AffiliateStatus::Rejected, $actor, [AffiliateStatus::Pending], $publicReason, $internalNote, 'affiliate.rejected');
    }

    public function suspend(AffiliateProfile $profile, User $actor, string $publicReason, ?string $internalNote = null): AffiliateProfile
    {
        return $this->transition($profile, AffiliateStatus::Suspended, $actor, [AffiliateStatus::Approved], $publicReason, $internalNote, 'affiliate.suspended');
    }

    public function reinstate(AffiliateProfile $profile, User $actor, string $publicReason, ?string $internalNote = null): AffiliateProfile
    {
        return $this->transition($profile, AffiliateStatus::Approved, $actor, [AffiliateStatus::Suspended], $publicReason, $internalNote, 'affiliate.reinstated');
    }

    public function deactivate(AffiliateProfile $profile, User $actor, string $publicReason, ?string $internalNote = null): AffiliateProfile
    {
        return $this->transition($profile, AffiliateStatus::Deactivated, $actor, [AffiliateStatus::Approved, AffiliateStatus::Suspended], $publicReason, $internalNote, 'affiliate.deactivated');
    }

    public function reactivate(AffiliateProfile $profile, User $actor, string $publicReason, ?string $internalNote = null): AffiliateProfile
    {
        return $this->transition($profile, AffiliateStatus::Approved, $actor, [AffiliateStatus::Deactivated], $publicReason, $internalNote, 'affiliate.reactivated');
    }

    /** A member closing their own membership. Suspended members cannot use this to escape a review. */
    public function closeOwnMembership(AffiliateProfile $profile, User $member, ?string $reason): AffiliateProfile
    {
        return $this->transition($profile, AffiliateStatus::Deactivated, $member, [AffiliateStatus::Approved], $reason, null, 'affiliate.closed_by_member', bySelf: true);
    }

    public function acceptTerms(AffiliateProfile $profile, AffiliateTermsVersion $terms): void
    {
        if ($profile->hasAcceptedTerms($terms)) {
            return;
        }

        DB::transaction(function () use ($profile, $terms) {
            $this->recordTermsAcceptance($profile, $terms);
            $this->audit->log('affiliate.terms_accepted', $profile, ['version' => $terms->version], $profile->user);
        });
    }

    public function addNote(AffiliateProfile $profile, User $author, string $body): void
    {
        DB::transaction(function () use ($profile, $author, $body) {
            $profile->notes()->create(['author_id' => $author->id, 'body' => $body]);
            $this->audit->log('affiliate.note_added', $profile, [], $author);
        });
    }

    public function reapplyAvailableAt(AffiliateProfile $profile): ?Carbon
    {
        if ($profile->status !== AffiliateStatus::Rejected) {
            return null;
        }

        return $profile->status_changed_at->copy()->addDays((int) config('affiliate.applications.reapply_cooldown_days'));
    }

    private function assertCanApply(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            throw MembershipException::because('Verify your email address before applying.', 'email');
        }

        // Staff approve applications and commissions, so they cannot also earn them.
        if ($user->isStaff()) {
            throw MembershipException::because('Staff accounts cannot join the affiliate programme.', 'account');
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function reapply(AffiliateProfile $profile, array $details, AffiliateTermsVersion $terms): AffiliateProfile
    {
        if ($profile->status !== AffiliateStatus::Rejected) {
            throw MembershipException::because('You already have an affiliate application or membership.');
        }

        $availableAt = $this->reapplyAvailableAt($profile);
        if ($availableAt && $availableAt->isFuture()) {
            throw MembershipException::because('You can apply again from '.$availableAt->timezone(config('affiliate.business_timezone'))->format('j M Y').'.');
        }

        $profile->fill($details);
        $profile->status = AffiliateStatus::Pending;
        $profile->applied_at = now();
        $profile->status_changed_at = now();
        $profile->save();

        $profile->statusHistory()->create([
            'from_status' => AffiliateStatus::Rejected, 'to_status' => AffiliateStatus::Pending, 'actor_id' => $profile->user_id,
        ]);
        $this->recordTermsAcceptance($profile, $terms);
        $this->audit->log('affiliate.reapplied', $profile, [], $profile->user);
        $this->notifyApplication($profile);

        return $profile;
    }

    /**
     * @param  list<AffiliateStatus>  $from  states this particular action may start from
     */
    private function transition(
        AffiliateProfile $profile,
        AffiliateStatus $to,
        User $actor,
        array $from,
        ?string $publicReason,
        ?string $internalNote,
        string $auditAction,
        bool $bySelf = false,
    ): AffiliateProfile {
        return DB::transaction(function () use ($profile, $to, $actor, $from, $publicReason, $internalNote, $auditAction, $bySelf) {
            // Re-read under lock so two reviewers clicking at once cannot both act.
            $locked = AffiliateProfile::whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if (! in_array($current, $from, true) || ! $current->canTransitionTo($to)) {
                throw MembershipException::because("This affiliate is {$current->label()}; that action is not available.");
            }

            if ($bySelf !== ($actor->id === $locked->user_id)) {
                throw MembershipException::because('You cannot review or change your own affiliate membership.');
            }

            $locked->status = $to;
            $locked->status_changed_at = now();

            if ($to === AffiliateStatus::Approved && $locked->referral_code === null) {
                $locked->referral_code = $this->identifiers->code();
                $locked->referral_slug = $this->identifiers->slug($locked->display_name, $locked->referral_code);
            }
            if ($to === AffiliateStatus::Approved && $current === AffiliateStatus::Pending) {
                $locked->approved_at = now();
                $locked->approved_by = $actor->id;
            }
            $locked->save();

            $this->syncAffiliateRole($locked, $to);

            $locked->statusHistory()->create([
                'from_status' => $current,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'public_reason' => $publicReason !== null ? Str::limit($publicReason, 1000, '') : null,
                'internal_note' => $internalNote,
            ]);

            $this->audit->log($auditAction, $locked, [
                'from' => $current->value,
                'to' => $to->value,
                'reason' => $publicReason,
            ], $actor);

            $locked->user->notify((new AffiliateStatusChanged($locked, $current, $to, $publicReason))->afterCommit());

            $profile->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * The affiliate role (and its portal permissions) is held only while the
     * membership can be used. Suspended members keep it so they can still see
     * their history; earning is blocked by status checks, not by the role.
     */
    private function syncAffiliateRole(AffiliateProfile $profile, AffiliateStatus $to): void
    {
        $user = $profile->user;

        if (in_array($to, [AffiliateStatus::Approved, AffiliateStatus::Suspended], true)) {
            if (! $user->hasRole(Role::Affiliate->value)) {
                $user->assignRole(Role::Affiliate->value);
            }
        } elseif ($user->hasRole(Role::Affiliate->value)) {
            $user->removeRole(Role::Affiliate->value);
        }
    }

    private function recordTermsAcceptance(AffiliateProfile $profile, AffiliateTermsVersion $terms): void
    {
        if ($profile->hasAcceptedTerms($terms)) {
            return;
        }

        $profile->termsAcceptances()->create([
            'terms_version_id' => $terms->id,
            'accepted_at' => now(),
            'ip_hash' => $this->audit->hashIp($this->request?->ip()),
            'user_agent' => $this->request?->userAgent() ? Str::limit($this->request->userAgent(), 250, '') : null,
        ]);
    }

    private function notifyApplication(AffiliateProfile $profile): void
    {
        $profile->user->notify((new ApplicationReceived($profile))->afterCommit());

        $reviewers = User::permission(Permission::AffiliatesReview->value)->where('status', 'active')->get();
        Notification::send($reviewers, (new NewApplicationForReview($profile))->afterCommit());
    }
}

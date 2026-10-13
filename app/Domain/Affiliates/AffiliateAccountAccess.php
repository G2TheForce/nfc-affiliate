<?php

namespace App\Domain\Affiliates;

use App\Domain\Audit\AuditLogger;
use App\Domain\Staff\StaffAccounts;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AffiliateProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Staff control over an affiliate's sign-in, separate from the membership:
 * block or allow sign-in, email a password reset link, sign out of every
 * device, resend the verification email. Staff never see or set a password.
 * Accounts that also hold a staff role are managed on the Staff screen only.
 */
class AffiliateAccountAccess
{
    public function __construct(
        private readonly StaffAccounts $accounts,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function summary(AffiliateProfile $profile): array
    {
        $user = $profile->user;

        return [
            'status' => $user->status->value,
            'email_verified' => $user->hasVerifiedEmail(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'signed_in_devices' => DB::table('sessions')->where('user_id', $user->getKey())->count() + $user->tokens()->count(),
            'is_staff' => $this->isStaff($user),
        ];
    }

    /** Disabling signs the account out everywhere (see StaffAccounts::setStatus). */
    public function setStatus(AffiliateProfile $profile, UserStatus $status, string $reason, User $actor): void
    {
        $user = $this->manageable($profile, $actor);
        $this->accounts->setStatus($user, $status, $reason, $actor, 'affiliate_screen');
    }

    public function sendPasswordReset(AffiliateProfile $profile, User $actor): void
    {
        $user = $this->active($this->manageable($profile, $actor));

        $status = Password::broker()->sendResetLink(['email' => $user->email]);
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['account' => 'A reset link was sent moments ago. Wait a minute before sending another.']);
        }
        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['account' => 'The reset link could not be sent.']);
        }

        $this->audit->log('admin.affiliate_password_reset_sent', $user, [], $actor);
    }

    public function signOutEverywhere(AffiliateProfile $profile, User $actor): int
    {
        $user = $this->manageable($profile, $actor);

        return DB::transaction(function () use ($user, $actor) {
            $count = $user->tokens()->delete() + DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $this->audit->log('admin.affiliate_signed_out', $user, ['devices' => $count], $actor);

            return $count;
        });
    }

    public function resendVerification(AffiliateProfile $profile, User $actor): void
    {
        $user = $this->active($this->manageable($profile, $actor));
        if ($user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['account' => 'This email address is already verified.']);
        }

        $user->sendEmailVerificationNotification();
        $this->audit->log('admin.affiliate_verification_resent', $user, [], $actor);
    }

    private function manageable(AffiliateProfile $profile, User $actor): User
    {
        $user = $profile->user;
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['account' => 'Use your own profile page to manage your sign-in.']);
        }
        if ($this->isStaff($user)) {
            throw ValidationException::withMessages(['account' => 'This person is also staff. Manage their sign-in on the Staff screen.']);
        }

        return $user;
    }

    private function active(User $user): User
    {
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['account' => 'Sign-in is blocked for this account. Allow sign-in first.']);
        }

        return $user;
    }

    private function isStaff(User $user): bool
    {
        return $user->roles->contains(fn ($r) => Role::tryFrom($r->name)?->isStaff());
    }
}

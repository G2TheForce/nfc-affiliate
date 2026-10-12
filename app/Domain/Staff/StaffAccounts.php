<?php

namespace App\Domain\Staff;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\Auth\StaffInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff accounts from the Staff screen and the console. A role is never
 * changed in place: disable the account and create a new one, so the audit
 * log keeps who did what under which role.
 */
class StaffAccounts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Creates the account with no usable password and emails a link to set one.
     */
    public function invite(string $name, string $email, Role $role, User $actor): User
    {
        if (! $role->isStaff()) {
            throw ValidationException::withMessages(['role' => 'Choose a staff role.']);
        }

        $user = DB::transaction(function () use ($name, $email, $role, $actor) {
            $user = User::create(['name' => trim($name), 'email' => mb_strtolower(trim($email)), 'password' => Str::password(64)]);
            // The invitation link proves the address, as a password reset does.
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($role->value);
            $this->audit->log('admin.staff_created', $user, ['via' => 'screen', 'role' => $role->value], $actor);

            return $user;
        });

        $this->sendInvitation($user, $actor, first: true);

        return $user;
    }

    /**
     * A new set-password link, for an invitation that expired or went astray.
     */
    public function sendInvitation(User $user, User $actor, bool $first = false): void
    {
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['status' => 'Re-enable the account before sending an invitation.']);
        }

        $token = Password::broker()->createToken($user);
        $user->notify(new StaffInvitation($token, $user->roles->first()?->name ?? ''));

        if (! $first) {
            $this->audit->log('admin.staff_invitation_resent', $user, [], $actor);
        }
    }

    /**
     * Swaps the person's staff role. Their past actions stay in the audit log
     * under their name; this entry records when the role changed. Nobody can
     * change their own role, and the last active super administrator keeps it.
     */
    public function changeRole(User $user, Role $role, User $actor): bool
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }
        if ($user->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['role' => 'Re-enable the account before changing its role.']);
        }
        $current = collect(Role::cases())->first(fn (Role $r) => $r->isStaff() && $user->hasRole($r->value));
        if ($current === $role) {
            return false;
        }
        if ($current === Role::SuperAdmin
            && User::role(Role::SuperAdmin->value)->where('status', UserStatus::Active->value)->whereKeyNot($user->getKey())->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'This is the only active super administrator. Make someone else a super administrator first.']);
        }

        DB::transaction(function () use ($user, $role, $current, $actor) {
            if ($current) {
                $user->removeRole($current->value);
            }
            $user->assignRole($role->value);
            // A new role means new permissions: sign them out so nothing runs on the old ones.
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $this->audit->log('admin.staff_role_changed', $user, ['from' => $current?->value, 'to' => $role->value], $actor);
        });

        return true;
    }

    /**
     * Disabling signs the account out everywhere. The last active super
     * administrator cannot be disabled, and nobody can disable themselves.
     */
    public function setStatus(User $user, UserStatus $status, string $reason, ?User $actor, string $via = 'screen'): bool
    {
        if ($status === UserStatus::Disabled) {
            if ($actor && $actor->is($user)) {
                throw ValidationException::withMessages(['status' => 'You cannot disable your own account.']);
            }
            if ($user->hasRole(Role::SuperAdmin->value)
                && User::role(Role::SuperAdmin->value)->where('status', UserStatus::Active->value)->whereKeyNot($user->getKey())->doesntExist()) {
                throw ValidationException::withMessages(['status' => 'This is the only active super administrator. Create another one first.']);
            }
        }

        if ($user->status === $status) {
            return false;
        }

        DB::transaction(function () use ($user, $status, $reason, $actor, $via) {
            $previous = $user->status->value;
            $user->forceFill(['status' => $status])->save();
            if ($status === UserStatus::Disabled) {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }
            $this->audit->log('user.status_changed', $user, ['via' => $via, 'from' => $previous, 'to' => $status->value, 'reason' => $reason], $actor);
        });

        return true;
    }
}
